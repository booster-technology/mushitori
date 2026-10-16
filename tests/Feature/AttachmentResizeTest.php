<?php

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use App\Support\JpegOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| 大きな画像の自動縮小(長辺 2560px まで)とJPEGの向き補正のテスト
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->customer = User::factory()->customer()->create();
    $project = Project::factory()->create(['key' => 'WEB']);
    $project->members()->attach($this->customer->id);
    loginAs($this->customer);
});

/**
 * 左半分が赤・右半分が青のテスト画像を作る(回転の向きを色で確かめられるように)
 */
function testImage(string $name, int $width, int $height, string $format = 'png', ?int $orientation = null, bool $transparentCorner = false): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));
    if ($transparentCorner) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 199, 199, imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);
    $data = (string) ob_get_clean();
    if ($orientation !== null) {
        $data = withExifOrientation($data, $orientation);
    }

    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, $data);

    return new UploadedFile($path, $name, $format === 'png' ? 'image/png' : 'image/jpeg', null, true);
}

/**
 * JPEGに向き情報だけのEXIFを埋め込む(スマホで縦向きに撮った写真の再現)
 */
function withExifOrientation(string $jpeg, int $orientation): string
{
    // TIFFヘッダー(リトルエンディアン) + IFD0(エントリ1つ: Orientation = SHORT)
    $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function uploadIssueWith(UploadedFile $file)
{
    return test()->post('/projects/WEB/issues', [
        'type' => 'bug', 'title' => '画像テスト', 'priority' => 'normal', 'attachments' => [$file],
    ]);
}

function storedAttachment()
{
    return Issue::first()->attachments()->first();
}

test('長辺2560pxを超えるPNGは、PNGのまま長辺2560pxに縮小される', function () {
    uploadIssueWith(testImage('big.png', 3200, 1800))->assertRedirect('/issues/WEB-1');

    $attachment = storedAttachment();
    $path = Storage::disk('local')->path($attachment->path);
    [$width, $height, $type] = getimagesize($path);

    expect([$width, $height])->toBe([2560, 1440])
        ->and($type)->toBe(IMAGETYPE_PNG)
        ->and($attachment->mime_type)->toBe('image/png')
        ->and([$attachment->width, $attachment->height])->toBe([2560, 1440])
        ->and($attachment->size)->toBe(filesize($path));
});

test('長辺2560px以下の画像(フルHDのスクリーンショット等)は一切変更せずに保存する', function () {
    $file = testImage('fullhd.png', 1920, 1080);
    $originalHash = md5_file($file->getRealPath());

    uploadIssueWith($file);

    $path = Storage::disk('local')->path(storedAttachment()->path);
    expect(md5_file($path))->toBe($originalHash);
});

test('縦向きで撮ったスマホ写真(EXIF向き=6)は、縮小時に正しい向きに回転して保存する', function () {
    // 横長の画素(左:赤/右:青) + 「右に90度回して表示」の情報 = 見た目は縦長(上:赤/下:青)
    uploadIssueWith(testImage('photo.jpg', 3000, 1500, 'jpeg', 6));

    $attachment = storedAttachment();
    $path = Storage::disk('local')->path($attachment->path);
    [$width, $height] = getimagesize($path);
    expect([$width, $height])->toBe([1280, 2560])
        ->and([$attachment->width, $attachment->height])->toBe([1280, 2560])
        // 回転を画素に反映したので、向き情報は残っていない(位置情報などのEXIFも消える)
        ->and(JpegOrientation::read($path))->toBe(1);

    $image = imagecreatefromjpeg($path);
    $top = imagecolorsforindex($image, imagecolorat($image, 640, 200));
    $bottom = imagecolorsforindex($image, imagecolorat($image, 640, 2360));
    expect($top['red'])->toBeGreaterThan(200)->and($top['blue'])->toBeLessThan(60)
        ->and($bottom['blue'])->toBeGreaterThan(200)->and($bottom['red'])->toBeLessThan(60);
});

test('縮小しない小さなスマホ写真は元のまま保存し、縦横の情報とサムネイルは見た目の向きに合わせる', function () {
    $file = testImage('small.jpg', 800, 400, 'jpeg', 6);
    $originalHash = md5_file($file->getRealPath());

    uploadIssueWith($file);

    $attachment = storedAttachment();
    expect(md5_file(Storage::disk('local')->path($attachment->path)))->toBe($originalHash)
        ->and([$attachment->width, $attachment->height])->toBe([400, 800]);

    [$thumbWidth, $thumbHeight] = getimagesize(Storage::disk('local')->path($attachment->thumbnail_path));
    expect($thumbHeight)->toBeGreaterThan($thumbWidth);
});

test('透過PNGは縮小しても透過が保たれる', function () {
    uploadIssueWith(testImage('alpha.png', 3000, 3000, 'png', null, true));

    $image = imagecreatefrompng(Storage::disk('local')->path(storedAttachment()->path));
    $corner = imagecolorsforindex($image, imagecolorat($image, 5, 5));
    expect($corner['alpha'])->toBe(127);
});

test('EXIFの無いJPEGやJPEG以外のファイルは向き=1(補正なし)として扱う', function () {
    $plain = testImage('plain.jpg', 10, 10, 'jpeg');
    $png = testImage('plain.png', 10, 10);

    expect(JpegOrientation::read($plain->getRealPath()))->toBe(1)
        ->and(JpegOrientation::read($png->getRealPath()))->toBe(1)
        ->and(JpegOrientation::read(testImage('o8.jpg', 10, 10, 'jpeg', 8)->getRealPath()))->toBe(8)
        ->and(AttachmentService::MAX_IMAGE_EDGE)->toBe(2560);
});

test('メモリの上限(IMAGE_MEMORY_LIMIT)を超える画像は、縮小・サムネイル作成をせずにそのまま保存し、登録自体は成功する', function () {
    config(['app.image_memory_limit' => '40M']);
    $file = testImage('huge.png', 3000, 2000);
    $originalHash = md5_file($file->getRealPath());

    uploadIssueWith($file)->assertRedirect('/issues/WEB-1');

    $attachment = storedAttachment();
    expect(md5_file(Storage::disk('local')->path($attachment->path)))->toBe($originalHash)
        ->and($attachment->thumbnail_path)->toBeNull()
        ->and([$attachment->width, $attachment->height])->toBe([3000, 2000]);
});
