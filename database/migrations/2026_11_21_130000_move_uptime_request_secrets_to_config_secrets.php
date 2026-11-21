<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('checks')->where('type', 'uptime')->orderBy('id')->select(['id', 'config', 'config_secrets'])->lazyById(200, 'id') as $row) {
            /** @var stdClass&object{id: int, config: string, config_secrets: string|null} $row */
            $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config)) {
                continue;
            }
            $secrets = $row->config_secrets === null
                ? []
                : json_decode(Crypt::decryptString($row->config_secrets), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($secrets)) {
                $secrets = [];
            }

            $changed = false;
            foreach (['raw_payload', 'expected_final_redirect_url'] as $key) {
                if (is_string($config[$key] ?? null) && $config[$key] !== '') {
                    $secrets[$key] = $config[$key];
                    $config[$key] = null;
                    $changed = true;
                }
            }
            if (is_array($config['payload'] ?? null) && $config['payload'] !== []) {
                $payload = $config['payload'];
                $payloadSecrets = is_array($secrets['payload'] ?? null) ? $secrets['payload'] : [];
                foreach ($payload as $index => $pair) {
                    if (is_array($pair) && is_string($pair['value'] ?? null)) {
                        $payloadSecrets[$index] = ['value' => $pair['value']];
                        $pair['value'] = null;
                        $payload[$index] = $pair;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $config['payload'] = $payload;
                }
                if ($payloadSecrets !== []) {
                    $secrets['payload'] = $payloadSecrets;
                }
            }

            if ($changed) {
                DB::table('checks')->where('id', $row->id)->update([
                    'config' => json_encode((object) $config, JSON_THROW_ON_ERROR),
                    'config_secrets' => $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('checks')->where('type', 'uptime')->whereNotNull('config_secrets')->orderBy('id')->select(['id', 'config', 'config_secrets'])->lazyById(200, 'id') as $row) {
            /** @var stdClass&object{id: int, config: string, config_secrets: string} $row */
            $config = json_decode($row->config, true, flags: JSON_THROW_ON_ERROR);
            $secrets = json_decode(Crypt::decryptString($row->config_secrets), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($config) || ! is_array($secrets)) {
                continue;
            }

            $changed = false;
            foreach (['raw_payload', 'expected_final_redirect_url'] as $key) {
                if (is_string($secrets[$key] ?? null)) {
                    $config[$key] = $secrets[$key];
                    unset($secrets[$key]);
                    $changed = true;
                }
            }
            if (is_array($secrets['payload'] ?? null) && is_array($config['payload'] ?? null)) {
                $payload = $config['payload'];
                foreach ($secrets['payload'] as $index => $pair) {
                    if (is_array($pair) && is_string($pair['value'] ?? null) && is_array($payload[$index] ?? null)) {
                        $entry = $payload[$index];
                        $entry['value'] = $pair['value'];
                        $payload[$index] = $entry;
                        $changed = true;
                    }
                }
                $config['payload'] = $payload;
                unset($secrets['payload']);
            }

            if ($changed) {
                DB::table('checks')->where('id', $row->id)->update([
                    'config' => json_encode((object) $config, JSON_THROW_ON_ERROR),
                    'config_secrets' => $secrets === [] ? null : Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR)),
                ]);
            }
        }
    }
};
