<?php

namespace Tests\Feature\Attachments;

use App\Core\Accounts\User;
use App\Core\Attachments\ImageOptimiser;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAttachmentFiles;
use Tests\TestCase;

class ImageOptimisationTest extends TestCase
{
    use BuildsAttachmentFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('The GD extension is required for image optimisation.');
        }

        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('private');
        Notification::fake();
    }

    public function test_large_screenshots_are_resized_to_at_most_2560_pixels(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), [
                'screenshots' => [
                    UploadedFile::fake()->image('wide.png', 3000, 1000),
                    UploadedFile::fake()->image('small.jpg', 800, 600),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sizes = $ticket->attachments()->orderBy('id')->get()
            ->map(fn ($attachment): array => array_slice(getimagesizefromstring(Storage::disk('private')->get($attachment->path)), 0, 2))
            ->all();

        $this->assertSame([[2560, 853], [800, 600]], $sizes);
    }

    public function test_exif_and_gps_data_are_removed_and_originals_are_not_kept(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->cicTicketFor($owner);
        $photo = $this->jpegWithExif('holiday.jpg');

        $this->actingAs($owner)
            ->put(route('apes-cic.tickets.update', $ticket), ['screenshots' => [$photo]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $attachment = $ticket->attachments()->firstOrFail();
        $stored = Storage::disk('private')->get($attachment->path);

        $this->assertStringStartsWith("\xFF\xD8", $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPSLatitude', $stored);
        $this->assertSame('holiday.jpg', $attachment->original_name);
        $this->assertSame(strlen($stored), (int) $attachment->size_bytes);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_images_gd_cannot_read_are_left_unchanged(): void
    {
        $file = UploadedFile::fake()->createWithContent('broken.png', 'not really an image');

        $this->assertSame($file, app(ImageOptimiser::class)->optimise($file));
    }

    private function jpegWithExif(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 140));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $payload = "Exif\0\0MM\0*\0\0\0\x08GPSLatitude 51.5074 N GPSLongitude 0.1278 W";
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $withExif = substr($jpeg, 0, 2).$segment.substr($jpeg, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif-test-');
        file_put_contents($path, $withExif);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
