<?php

namespace App\Services;

use App\Models\SetupConfiguration;

class SetupConfigurationService
{
    public function current(): SetupConfiguration
    {
        return SetupConfiguration::query()->first() ?: new SetupConfiguration([
            'server_port' => 22,
            'drupal_site_scheme' => 'https',
            'is_completed' => false,
        ]);
    }

    public function isComplete(): bool
    {
        $setup = SetupConfiguration::query()->first();

        return (bool) ($setup?->is_completed);
    }

    public function preview(array $attributes): SetupConfiguration
    {
        $setup = $this->current();

        $resolved = $attributes;
        if (($resolved['server_password'] ?? '') === '' && $setup->exists && ! empty($setup->server_password)) {
            $resolved['server_password'] = $setup->server_password;
        }
        if (($resolved['default_website_password'] ?? '') === '' && $setup->exists && ! empty($setup->default_website_password)) {
            $resolved['default_website_password'] = $setup->default_website_password;
        }
        if (($resolved['default_api_key'] ?? '') === '' && $setup->exists && ! empty($setup->default_api_key)) {
            $resolved['default_api_key'] = $setup->default_api_key;
        }

        $setup->fill($resolved);

        return $setup;
    }

    public function persist(array $attributes, bool $isCompleted = false): SetupConfiguration
    {
        $setup = SetupConfiguration::query()->first() ?: new SetupConfiguration([
            'server_port' => 22,
            'drupal_site_scheme' => 'https',
        ]);

        if (($attributes['server_password'] ?? '') === '' && $setup->exists) {
            unset($attributes['server_password']);
        }
        if (($attributes['default_website_password'] ?? '') === '' && $setup->exists) {
            unset($attributes['default_website_password']);
        }
        if (($attributes['default_api_key'] ?? '') === '' && $setup->exists) {
            unset($attributes['default_api_key']);
        }

        $setup->fill($attributes);
        $setup->is_completed = $isCompleted;
        $setup->save();

        return $setup->fresh();
    }

    public function isRemoteConfigured(?SetupConfiguration $setup = null): bool
    {
        $setup ??= SetupConfiguration::query()->first();

        return $setup !== null
            && filled($setup->server_host)
            && filled($setup->server_username)
            && filled($setup->drupal_project_path)
            && filled($setup->auth_site_domain);
    }
}
