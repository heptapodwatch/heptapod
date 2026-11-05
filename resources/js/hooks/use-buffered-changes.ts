import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export function useBufferedChanges<T>(
    rows: T[],
    key: (row: T) => number | string,
    scope?: number | null,
    // Opt-in: a row whose content changed keeps its shown object until apply, so filters and text stay as shown.
    contentKey?: (row: T) => string,
    // Opt-in for lists this tab's own mutations change: names the response prop holding the rows. While such a
    // mutation is in flight, incoming rows wait; its successful response is shown directly and never counted as pending.
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
    const ownRunAccepts = useRef(ownRun?.accepts);
    ownRunAccepts.current = ownRun?.accepts;
    const [visible, setVisible] = useState(rows);
    const [pendingCount, setPendingCount] = useState(0);
    const [ownMutationsInFlight, setOwnMutationsInFlight] = useState(0);
    const holdRows = ownResponseProp !== undefined && ownMutationsInFlight > 0;

    useEffect(() => {
        latest.current = rows;
        if (previousUrl.current !== url || previousScope.current !== scope) {
            previousUrl.current = url;
            previousScope.current = scope;
            setVisible(rows);
            setPendingCount(0);
            return;
        }
        if (holdRows) {
            return;
        }
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
        const oldKeys = base.map(getKey.current);
        const newKeys = rows.map(getKey.current);
        const removedKeys = oldKeys.filter((id) => !newKeys.includes(id));
        const addedKeys = newKeys.filter((id) => !oldKeys.includes(id));
        const orderChanged = oldKeys.some((id, index) => id !== newKeys[index]);
        const shownByKey = new Map(
            base.map((row) => [getKey.current(row), row]),
        );
        const contentChangedKeys = contentKey
            ? rows
                  .filter((row) => {
                      const shown = shownByKey.get(getKey.current(row));
                      return (
                          shown !== undefined &&
                          contentKey(shown) !== contentKey(row)
                      );
                  })
                  .map((row) => getKey.current(row))
            : [];
        // With content buffering each changed row counts once, so the count never grows across updates of one row.
        const differences = contentKey
            ? new Set([...removedKeys, ...addedKeys, ...contentChangedKeys])
                  .size || (orderChanged ? 1 : 0)
            : Math.max(
                  removedKeys.length,
                  addedKeys.length,
                  orderChanged ? 1 : 0,
              );
        if (differences > 0) {
            const freshByKey = new Map(
                rows.map((row) => [getKey.current(row), row]),
            );
            setVisible(
                base.map((row) => {
                    const fresh = freshByKey.get(getKey.current(row));
                    if (fresh === undefined) {
                        return row;
                    }
                    return contentKey !== undefined &&
                        contentKey(row) !== contentKey(fresh)
                        ? row
                        : fresh;
                }),
            );
            setPendingCount(differences);
        } else {
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
            latest.current = acceptedRows as T[];
            setVisible(acceptedRows as T[]);
            setPendingCount(0);
        });
        const removeFinish = router.on('finish', ({ detail }) => {
            if (!mutationVisitIds.delete(detail.visit.id)) {
                return;
            }
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
            setVisible(latest.current);
            setPendingCount(0);
        },
    };
}
