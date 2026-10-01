<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Fixtures;

use Whitesmoke\Http\NotFound;
use Whitesmoke\Http\Request;
use Whitesmoke\Http\Response;

final class TestController
{
    public function index(Request $request): Response
    {
        return Response::text('home');
    }

    public function show(Request $request): Response
    {
        $id = $request->getInt('id') ?? throw new NotFound();

        return Response::text("item {$id}");
    }

    public function boom(Request $request): Response
    {
        throw new \RuntimeException('secret internal detail');
    }

    public function store(Request $request): Response
    {
        return Response::redirect('/item?id=1');
    }
}
