<?php

namespace Heptapod\Concerns;

use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Models must explicitly select public configuration; never use logAll/logFillable. */
trait AuditsConfiguration
{
    use LogsActivity;

    /** @return list<string> */
    abstract protected function auditedAttributes(): array;

    /** @return list<string> */
    protected function secretAttributes(): array
    {
        return [];
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        if ($eventName === 'deleted') {
            $identity = ['type' => $this->getMorphClass(), 'id' => $this->getKey()];
            $attributes = array_intersect_key($this->getAttributes(), array_flip(array_diff($this->auditedAttributes(), $this->secretAttributes())));
            foreach (array_keys($attributes) as $attribute) {
                $identity[$attribute] = $this->getAttribute($attribute);
            }
            $identity['name'] ??= $identity['label'] ?? $identity['friendly_name'] ?? $identity['url'] ?? null;
            $activity->properties = ($activity->properties ?? collect())->merge(['identity' => $identity]);
        }
        $changed = array_values(array_intersect($this->secretAttributes(), array_keys($this->getDirty())));
        if ($changed !== []) {
            $activity->properties = ($activity->properties ?? collect())->merge(['changed_secret_fields' => $changed]);
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        $options = LogOptions::defaults()->useLogName('audit')->logOnly($this->auditedAttributes())
            ->logOnlyDirty()->dontLogEmptyChanges();

        return $this->secretAttributes() !== [] && $this->isDirty($this->secretAttributes()) ? $options->logEmptyChanges() : $options;
    }
}
