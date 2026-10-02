<?php
declare(strict_types=1);

/*
 * Router for RememberMeTest, run with PHP's built-in server so cookies and sessions are real.
 * Uses the SQLite file in DB_DATABASE and the fixture app's session settings.
 *   /setup                  fresh users and remember_tokens tables, user 1 with password "first"
 *   /login?remember=1       log user 1 in (and remember this device)
 *   /whoami                 "1" or "guest", as the auth middleware decides
 *   /logout                 forget this device and end the session
 *   /password               change user 1's password
 *   /expire                 make every token expired
 *   /tokens                 the remember_tokens rows as JSON
 */

use Whitesmoke\Auth\RememberMe;
use Whitesmoke\Auth\SessionUser;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Database\Schema\Schema;
use Whitesmoke\Http\Request;

require __DIR__ . '/../../vendor/autoload.php';

define('BASE_PATH', __DIR__ . '/app');
define('WS_TEST_TMP', (string) getenv('WS_TEST_TMP'));

$request  = Request::capture();
$remember = new RememberMe();
$sessions = new SessionUser();

switch ($request->path()) {
    case '/setup':
        $schema = new Schema(db());
        $schema->dropIfExists('remember_tokens');
        $schema->dropIfExists('users');
        $schema->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('password');
        });
        $schema->create('remember_tokens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users', onDelete: 'cascade');
            $t->string('selector', 24)->unique();
            $t->string('validator_hash', 64);
            $t->string('password_fingerprint', 64);
            $t->bigInteger('expires_at');
        });
        table('users')->insert(['password' => password_hash('first', PASSWORD_DEFAULT)]);
        echo 'ok';
        break;

    case '/login':
        $user = table('users')->where('id', '=', 1)->first();
        $sessions->remember($user);
        if ($request->get('remember') === '1') {
            $remember->issue($user);
        }
        echo 'ok';
        break;

    case '/whoami':
        $user = $sessions->check();
        if ($user === null && ($user = $remember->user($request)) !== null) {
            $sessions->remember($user);
        }
        echo $user === null ? 'guest' : (string) $user['id'];
        break;

    case '/logout':
        $remember->forget($request);
        session()->invalidate();
        echo 'out';
        break;

    case '/password':
        table('users')->where('id', '=', 1)->update(['password' => password_hash('second', PASSWORD_DEFAULT)]);
        echo 'ok';
        break;

    case '/expire':
        table('remember_tokens')->where('id', '>', 0)->update(['expires_at' => time() - 1]);
        echo 'ok';
        break;

    case '/tokens':
        header('Content-Type: application/json');
        echo json_encode(table('remember_tokens')->orderBy('id')->get());
        break;
}

session()->close();
