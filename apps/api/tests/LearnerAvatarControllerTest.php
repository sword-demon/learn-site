<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\LearnerAvatarController;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\Http\UploadFile;
use Webman\ThinkOrm\ThinkOrm;

final class LearnerAvatarControllerTest extends TestCase
{
    private string $root;
    private int $learnerId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/learn-site-avatar-controller-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0775, true);
        Db::startTrans();
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '138' . random_int(10000000, 99999999),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-28 10:00:00',
            'updated_at' => '2026-08-28 10:00:00',
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '林间学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => '2026-08-28 10:00:00',
            'updated_at' => '2026-08-28 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->root . '/' . $entry);
            }
        }
        @rmdir($this->root);
    }

    public function testUploadStoresImageAndWritesLearnerAvatarUrl(): void
    {
        $url = '/api/media/avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
        $storage = new FakeImageStorage([
            'key' => 'avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
            'url' => $url,
            'mime_type' => 'image/png',
            'size_bytes' => 32,
        ]);
        $request = new CoverRequest($this->file('avatar.png', 'image/png', 32));
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerId;

        $response = (new LearnerAvatarController($storage))->upload($request);
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($url, $body['data']['avatar_url']);
        self::assertSame($this->learnerId, $body['data']['account_id']);
        self::assertSame('林间学员', $body['data']['nickname']);
        self::assertSame(['image/png', 'png'], $storage->storedArguments);
        self::assertSame($url, Db::name('learners')->where('account_id', $this->learnerId)->value('avatar_url'));
    }

    public function testMissingFileIsRejectedWithoutCallingStorage(): void
    {
        $storage = new FakeImageStorage();
        $request = new CoverRequest(null);
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerId;

        $response = (new LearnerAvatarController($storage))->upload($request);
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('AVATAR_FILE_REQUIRED', $body['error']['message']);
        self::assertNull($storage->storedArguments);
        self::assertNull(Db::name('learners')->where('account_id', $this->learnerId)->value('avatar_url'));
    }

    public function testMissingLearnerCannotUpload(): void
    {
        $storage = new FakeImageStorage();
        $request = new CoverRequest($this->file('avatar.png', 'image/png', 32));
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerId + 999;

        $response = (new LearnerAvatarController($storage))->upload($request);
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('LEARNER_NOT_FOUND', $body['error']['message']);
        self::assertNull($storage->storedArguments);
    }

    public function testDestroyClearsAvatarUrlWithoutDeletingTheStoredFile(): void
    {
        $url = '/api/media/avatars/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp';
        Db::name('learners')->where('account_id', $this->learnerId)->update(['avatar_url' => $url]);
        $storage = new FakeImageStorage();
        $request = new CoverRequest(null);
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->learnerId;

        $response = (new LearnerAvatarController($storage))->destroy($request);
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($body['data']['avatar_url']);
        self::assertNull($storage->storedArguments);
        self::assertNull(Db::name('learners')->where('account_id', $this->learnerId)->value('avatar_url'));
    }

    private function file(string $name, string $mime, int $size): UploadFile
    {
        $path = $this->root . '/' . $name;
        $pngFixture = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8A'
            . 'AQUBAScY42YAAAAASUVORK5CYII=';
        $content = $mime === 'image/png'
            ? base64_decode($pngFixture, true)
            : str_repeat('x', $size);
        file_put_contents($path, $content);
        return new UploadFile($path, $name, $mime, UPLOAD_ERR_OK);
    }
}
