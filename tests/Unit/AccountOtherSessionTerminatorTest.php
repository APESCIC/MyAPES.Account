<?php

namespace Tests\Unit;

use App\Core\Accounts\User;
use App\Services\AccountOtherSessionTerminator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountOtherSessionTerminatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminator_deletes_other_database_sessions_and_bumps_authorization_epoch(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['authorization_epoch' => 4]);
        $request = Request::create('/account/security', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->start();
        $request->session()->put('myapes.authentication_method', 'password');
        $request->session()->put('myapes.authorization_epoch', 4);
        $request->setUserResolver(static fn () => $user);

        $currentId = $request->session()->getId();
        DB::table('sessions')->insert([
            'id' => $currentId,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'stale-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time() - 60,
        ]);

        app(AccountOtherSessionTerminator::class)->terminateOtherSessions($request, $user);

        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-session']);
        $this->assertSame(5, $user->refresh()->authorization_epoch);
        $this->assertSame(5, (int) $request->session()->get('myapes.authorization_epoch'));
    }
}