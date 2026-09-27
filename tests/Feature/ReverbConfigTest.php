<?php

namespace Tests\Feature;

use Laravel\Reverb\Contracts\ApplicationProvider;
use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    public function test_reverb_config_structure_and_provider(): void
    {
        $this->assertSame('config', config('reverb.apps.provider'));
        $this->assertNotEmpty(config('reverb.apps.apps'));

        $configuredKey = config('reverb.apps.apps.0.key');
        $this->assertSame(config('broadcasting.connections.reverb.key'), $configuredKey);

        $provider = app(ApplicationProvider::class);
        $appByKey = $provider->findByKey(config('broadcasting.connections.reverb.key'));
        $this->assertNotNull($appByKey);
        $this->assertSame(config('broadcasting.connections.reverb.app_id'), (string) $appByKey->id());

        $appById = $provider->findById(config('broadcasting.connections.reverb.app_id'));
        $this->assertNotNull($appById);
        $this->assertSame($configuredKey, $appById->key());
    }
}