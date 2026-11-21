import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function countDifferences<T>(
    shown: T[],
    fresh: T[],
    getKey: (row: T) => number | string,
    contentKey?: (row: T) => string,
): number {
    const oldKeys = shown.map(getKey);
    const newKeys = fresh.map(getKey);
    const removedKeys = oldKeys.filter((id) => !newKeys.includes(id));
    const addedKeys = newKeys.filter((id) => !oldKeys.includes(id));
    const orderChanged = oldKeys.some((id, index) => id !== newKeys[index]);
    const shownByKey = new Map(shown.map((row) => [getKey(row), row]));
    const contentChangedKeys = contentKey
        ? fresh
              .filter((row) => {
                  const current = shownByKey.get(getKey(row));
                  return (
                      current !== undefined &&
                      contentKey(current) !== contentKey(row)
                  );
              })
              .map(getKey)
        : [];
    // With content buffering each changed row counts once, so the count never grows across updates of one row.
    return contentKey
        ? new Set([...removedKeys, ...addedKeys, ...contentChangedKeys]).size ||
              (orderChanged ? 1 : 0)
        : Math.max(removedKeys.length, addedKeys.length, orderChanged ? 1 : 0);
}

/**
 * Applies only the rows this tab's own mutation added, removed or modified (comparing `accepted` against the latest
 * server snapshot seen before the mutation), leaving previously buffered foreign changes behind `pendingCount`.
 */
function mergeOwnMutation<T>(
    shown: T[],
    previousLatest: T[],
    accepted: T[],
    getKey: (row: T) => number | string,
    contentKey?: (row: T) => string,
): { rows: T[]; pendingCount: number } {
    if (countDifferences(shown, previousLatest, getKey, contentKey) === 0) {
        return { rows: accepted, pendingCount: 0 };
    }
    const prevByKey = new Map(previousLatest.map((row) => [getKey(row), row]));
    const acceptedByKey = new Map(accepted.map((row) => [getKey(row), row]));
    const ownRemoved = new Set(
        [...prevByKey.keys()].filter((id) => !acceptedByKey.has(id)),
    );
    const ownAdded = new Set(
        [...acceptedByKey.keys()].filter((id) => !prevByKey.has(id)),
    );
    const ownModified = contentKey
        ? new Set(
              [...acceptedByKey.entries()]
                  .filter(([id, row]) => {
                      const before = prevByKey.get(id);
                      return (
                          before !== undefined &&
                          contentKey(before) !== contentKey(row)
                      );
                  })
                  .map(([id]) => id),
          )
        : new Set<number | string>();

    // Remove deleted and own-modified rows so own-modified rows can be placed at their new server order
    // (e.g. a rescheduled maintenance window) without disturbing buffered foreign rows or foreign reorders.
    const nextVisible = shown
        .filter((row) => {
            const id = getKey(row);
            return !ownRemoved.has(id) && !ownModified.has(id);
        })
        .map((row) => {
            const id = getKey(row);
            return contentKey === undefined && acceptedByKey.has(id)
                ? acceptedByKey.get(id)!
                : row;
        });

    for (let index = 0; index < accepted.length; index++) {
        const row = accepted[index]!;
        const id = getKey(row);
        if (!ownAdded.has(id) && !ownModified.has(id)) {
            continue;
        }
        if (nextVisible.some((item) => getKey(item) === id)) {
            continue;
        }
        // Insert before the earliest visible row that appears after `row` in `accepted`, preserving
        // the new server position of own rows while leaving any buffered foreign reorder untouched.
        const visibleIndexByKey = new Map(
            nextVisible.map((item, visibleIndex) => [
                getKey(item),
                visibleIndex,
            ]),
        );
        let position = nextVisible.length;
        for (let after = index + 1; after < accepted.length; after++) {
            const followingIndex = visibleIndexByKey.get(
                getKey(accepted[after]!),
            );
            if (followingIndex !== undefined && followingIndex < position) {
                position = followingIndex;
            }
        }
        nextVisible.splice(position, 0, row);
    }

    const differences = countDifferences(
        nextVisible,
        accepted,
        getKey,
        contentKey,
    );
    return differences === 0
        ? { rows: accepted, pendingCount: 0 }
        : { rows: nextVisible, pendingCount: differences };
}

export function useBufferedChanges<T>(
    // Must keep its identity between renders (a prop, or memoized); a fresh array each render loops the effect.
    rows: T[],
    key: (row: T) => number | string,
    scope?: number | null,
    // Opt-in: a row whose content changed keeps its shown object until apply, so filters and text stay as shown.
    contentKey?: (row: T) => string,
    // Opt-in for lists this tab's own mutations change: names the response prop holding the rows. While such a
    // mutation is in flight, incoming rows wait; its successful response shows only this mutation's changes directly
    // while keeping previously buffered foreign changes pending.
    ownResponseProp?: string,
    // The result of a run this tab requested, for one commit: rows it accepts are shown directly and never counted
    // as pending; every other change keeps buffering. `token` (the attempt id) re-evaluates the rows once per result.
    ownRun?: { token: number; accepts: (row: T) => boolean } | null,
) {
    const { url } = usePage();
    const latest = useRef(rows);
    const previousUrl = useRef(url);
    const previousScope = useRef(scope);
    const getKey = useRef(key);
    getKey.current = key;
    const contentKeyRef = useRef(contentKey);
    contentKeyRef.current = contentKey;
    const ownRunAccepts = useRef(ownRun?.accepts);
    ownRunAccepts.current = ownRun?.accepts;
    const [visible, setVisible] = useState(rows);
    const visibleRef = useRef(visible);
    visibleRef.current = visible;
    const [pendingCount, setPendingCount] = useState(0);
    const [ownMutationsInFlight, setOwnMutationsInFlight] = useState(0);
    const mutationsInFlightRef = useRef(0);
    const holdRows = ownResponseProp !== undefined && ownMutationsInFlight > 0;

    useEffect(() => {
        if (previousUrl.current !== url || previousScope.current !== scope) {
            previousUrl.current = url;
            previousScope.current = scope;
            latest.current = rows;
            visibleRef.current = rows;
            setVisible(rows);
            setPendingCount(0);
            return;
        }
        // Inertia commits response props via flushSync before firing `success`: keep `latest.current` at the
        // pre-response snapshot until `success` diffs the own mutation against it (or `finish` releases on error).
        if (
            holdRows ||
            (ownResponseProp !== undefined && mutationsInFlightRef.current > 0)
        ) {
            return;
        }
        latest.current = rows;
        let base = visible;
        const accepts = ownRunAccepts.current;
        if (accepts) {
            const acceptedByKey = new Map(
                rows.filter(accepts).map((row) => [getKey.current(row), row]),
            );
            base = visible.map(
                (row) => acceptedByKey.get(getKey.current(row)) ?? row,
            );
            let inserted = 0;
            rows.forEach((row, index) => {
                const rowKey = getKey.current(row);
                if (
                    acceptedByKey.has(rowKey) &&
                    !base.some((shown) => getKey.current(shown) === rowKey)
                ) {
                    base = [...base.slice(0, index), row, ...base.slice(index)];
                    inserted++;
                }
            });
            // In a bounded list each accepted new row pushes the oldest shown row out, as the server did.
            const freshKeys = new Set(rows.map(getKey.current));
            base = base
                .toReversed()
                .filter((row) => {
                    if (inserted > 0 && !freshKeys.has(getKey.current(row))) {
                        inserted--;
                        return false;
                    }
                    return true;
                })
                .toReversed();
        }
        const differences = countDifferences(
            base,
            rows,
            getKey.current,
            contentKey,
        );
        if (differences > 0) {
            const freshByKey = new Map(
                rows.map((row) => [getKey.current(row), row]),
            );
            const nextVisible = base.map((row) => {
                const fresh = freshByKey.get(getKey.current(row));
                if (fresh === undefined) {
                    return row;
                }
                return contentKey !== undefined &&
                    contentKey(row) !== contentKey(fresh)
                    ? row
                    : fresh;
            });
            visibleRef.current = nextVisible;
            setVisible(nextVisible);
            setPendingCount(differences);
        } else {
            visibleRef.current = rows;
            setVisible(rows);
            setPendingCount(0);
        }
    }, [rows, url, scope, holdRows, ownRun?.token]);

    useEffect(() => {
        if (ownResponseProp === undefined) {
            return;
        }
        const mutationVisitIds = new Set<string>();
        const removeStart = router.on('start', ({ detail }) => {
            if (detail.visit.method === 'get') {
                return;
            }
            mutationVisitIds.add(detail.visit.id);
            mutationsInFlightRef.current++;
            setOwnMutationsInFlight((count) => count + 1);
        });
        const removeSuccess = router.on('success', ({ detail }) => {
            if (
                detail.visitId === undefined ||
                !mutationVisitIds.has(detail.visitId)
            ) {
                return;
            }
            const responseValue = detail.page.props[ownResponseProp];
            // A singleton buffered record (such as incident state) comes back as one DTO, not a list.
            const acceptedRows = Array.isArray(responseValue)
                ? responseValue
                : responseValue !== null && typeof responseValue === 'object'
                  ? [responseValue]
                  : null;
            if (acceptedRows === null) {
                throw new Error(
                    `Own mutation response is missing the ${ownResponseProp} prop`,
                );
            }
            const merged = mergeOwnMutation(
                visibleRef.current,
                latest.current,
                acceptedRows as T[],
                getKey.current,
                contentKeyRef.current,
            );
            latest.current = acceptedRows as T[];
            visibleRef.current = merged.rows;
            setVisible(merged.rows);
            setPendingCount(merged.pendingCount);
        });
        const removeFinish = router.on('finish', ({ detail }) => {
            if (!mutationVisitIds.delete(detail.visit.id)) {
                return;
            }
            mutationsInFlightRef.current = Math.max(
                0,
                mutationsInFlightRef.current - 1,
            );
            setOwnMutationsInFlight((count) => count - 1);
        });
        return () => {
            removeStart();
            removeSuccess();
            removeFinish();
        };
    }, [ownResponseProp]);

    return {
        rows: previousScope.current !== scope ? rows : visible,
        pendingCount: previousScope.current !== scope ? 0 : pendingCount,
        apply: () => {
            visibleRef.current = latest.current;
            setVisible(latest.current);
            setPendingCount(0);
        },
    };
}
