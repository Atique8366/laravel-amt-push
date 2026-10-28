<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Support\Facades\Route;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Tests\TestCase;

class ManualRoutesTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('adms.iclock.enabled', false);
    }

    public function test_routes_can_be_registered_by_the_app_under_its_own_prefix()
    {
        $this->approvedDevice();
        $this->call('GET', '/iclock/cdata?SN=SN0001&options=all')->assertStatus(404);

        Route::prefix('push')->group(function () {
            Adms::routes();
        });
        app('router')->getRoutes()->refreshNameLookups();

        $this->call('GET', '/push/iclock/cdata?SN=SN0001&options=all')->assertStatus(200);
    }
}
