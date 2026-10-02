<?php
declare(strict_types=1);

/*
 * Router for UploadsTest, run with PHP's built-in server so uploads are real:
 *   POST /store?types=png,pdf&max=1000   stores the "file" field
 *   POST /store-fake                     tries to store a file that was not uploaded
 *   GET  /download?id=&name=&type=&inline=1
 * Files go to the directory in the WS_UPLOADS environment variable.
 */

use Whitesmoke\Http\Request;
use Whitesmoke\Storage\UploadRejected;
use Whitesmoke\Storage\Uploads;

require __DIR__ . '/../../vendor/autoload.php';

define('BASE_PATH', __DIR__ . '/app');

$uploads = new Uploads((string) getenv('WS_UPLOADS'));
$request = Request::capture();
$json    = static function (array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
};

try {
    switch ($request->path()) {
        case '/store':
            $file = $uploads->store($request->file('file'), explode(',', (string) $request->get('types', 'png')), (int) $request->get('max', '1000000'));
            $json(['ok' => true, 'id' => $file->id, 'name' => $file->name, 'type' => $file->type, 'size' => $file->size, 'extension' => $file->extension, 'stored' => is_file($uploads->path($file->id))]);
            break;

        case '/store-fake':
            $real = dirname(__DIR__, 2) . '/composer.json';
            $uploads->store(['name' => 'x.txt', 'tmp_name' => $real, 'error' => UPLOAD_ERR_OK, 'size' => filesize($real)], ['txt'], 1000000);
            $json(['ok' => true]);
            break;

        case '/download':
            $uploads->download((string) $request->get('id', ''), (string) $request->get('name', ''), (string) $request->get('type', ''), $request->get('inline') === '1')->send();
            break;

        default:
            http_response_code(404);
    }
} catch (UploadRejected $e) {
    $json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code($e instanceof \Whitesmoke\Http\HttpException ? $e->status() : 500);
    $json(['ok' => false, 'exception' => $e::class, 'error' => $e->getMessage()]);
}
