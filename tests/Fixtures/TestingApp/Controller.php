<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Fixtures\TestingApp;

use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;
use Whitesmoke\Mail\Message;

final class Controller
{
    public function form(Request $request): Response
    {
        $flash = session()->getFlash('saved');

        return Response::html(
            '<p>' . e($flash ?? 'nothing yet') . '</p>'
            . '<p>Notes: ' . table('notes')->count() . '</p>'
            . '<form method="post" action="/save">' . csrf_field() . '<input name="body"></form>'
        );
    }

    public function save(Request $request): Response
    {
        $body = (string) $request->post('body', '');

        table('notes')->insert(['body' => $body]);
        mailer()->send(new Message('ana@example.test', 'Note saved', "You saved: {$body}", cc: ['team@example.test']));
        session()->flash('saved', "Saved: {$body}");

        return Response::redirect('/');
    }
}
