<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BrandingImages;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class BrandingApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function image(string $name = 'image.png', int $width = 2, int $height = 2): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x20\x3d\x54", $width), $height)))
            .$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function upload(string $slot = 'story_light', ?UploadedFile $image = null)
    {
        return $this->post('/api/foundation/branding/'.$slot, ['image' => $image ?? $this->image()],
            ['Accept' => 'application/json']);
    }

    public function test_branding_map_and_mutations_require_authentication(): void
    {
        $this->getJson('/api/branding')->assertUnauthorized();
        $this->upload()->assertUnauthorized();
        $this->deleteJson('/api/foundation/branding/story_light')->assertUnauthorized();
        $this->get('/api/branding/images/story_light/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_any_active_user_can_read_but_settings_permission_alone_cannot_change_branding(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Branding settings manager']);
        $role->permissions()->sync(Permission::whereIn('code', ['settings.view', 'settings.update'])->pluck('id'));
        $user->roles()->attach($role);
        Sanctum::actingAs($user);
        $this->getJson('/api/branding')->assertOk()->assertExactJson([
            'data' => array_fill_keys(BrandingImages::SLOTS, null),
        ]);
        $this->upload()->assertForbidden();
        $this->deleteJson('/api/foundation/branding/story_light')->assertForbidden();
        $user->update(['is_active' => false]);
        $this->getJson('/api/branding')->assertForbidden();
        $this->assertDatabaseCount('branding_images', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_administrator_still_requires_settings_update_permission(): void
    {
        $this->actingAsAdmin();
        DB::table('role_permissions')->where('role_id', Role::where('name', 'Administrator')->value('id'))
            ->where('permission_id', Permission::where('code', 'settings.update')->value('id'))->delete();
        $this->upload()->assertForbidden();
        $this->deleteJson('/api/foundation/branding/story_light')->assertForbidden();
    }

    public function test_upload_public_image_replace_and_restore_are_persistent_and_audited(): void
    {
        $actor = $this->actingAsAdmin();
        $first = $this->upload()->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.story_light.width', 2)->assertJsonPath('data.story_light.height', 2)
            ->assertJsonPath('data.story_light.mime_type', 'image/png')
            ->assertJsonMissingPath('data.story_light.storage_path')->json('data.story_light');
        $old = DB::table('branding_images')->where('slot', 'story_light')->first();
        $this->assertStringStartsWith(BrandingImages::directory().'/', $old->storage_path);
        $this->assertDatabaseHas('branding_images', ['updated_by' => $actor->id]);
        Storage::disk('local')->assertExists($old->storage_path);
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('data.story_light.version', $first['version']);
        // The image can be rendered by a normal <img> without exposing an auth token.
        auth()->forgetGuards();
        $this->get($first['url'])->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $cache = $this->get($first['url'])->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('immutable', $cache);
        Sanctum::actingAs($actor);
        $second = $this->upload('story_light', $this->image('new.png', 3, 4))->assertOk()
            ->assertJsonPath('data.story_light.width', 3)->json('data.story_light');
        $this->assertNotSame($first['url'], $second['url']);
        Storage::disk('local')->assertMissing($old->storage_path);
        $this->get($first['url'])->assertNotFound();
        $this->deleteJson('/api/foundation/branding/story_light')->assertOk()
            ->assertJsonPath('data.story_light', null)->assertJsonCount(6, 'data');
        $this->deleteJson('/api/foundation/branding/story_light')->assertOk();
        $this->get($second['url'])->assertNotFound();
        $this->assertDatabaseCount('branding_images', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $audits = DB::table('audit_logs')->where('table_name', 'branding_images')->get();
        $this->assertSame(['create', 'update', 'delete'], $audits->pluck('action')->all());
        $this->assertStringNotContainsString('storage_path', $audits->toJson());
    }

    public function test_all_six_slots_are_independent_and_accept_supported_image_types(): void
    {
        $this->actingAsAdmin();
        foreach (BrandingImages::SLOTS as $index => $slot) {
            $extension = $index % 2 ? 'jpg' : 'webp';
            $file = UploadedFile::fake()->createWithContent('image.'.$extension,
                file_get_contents(base_path('tests/Fixtures/branding/sample.'.$extension)));
            $this->upload($slot, $file)->assertOk()->assertJsonPath('data.'.$slot.'.width', 12)
                ->assertJsonPath('data.'.$slot.'.height', 8)
                ->assertJsonPath('data.'.$slot.'.mime_type', $extension === 'jpg' ? 'image/jpeg' : 'image/webp');
        }
        $this->deleteJson('/api/foundation/branding/story_light')->assertOk()
            ->assertJsonPath('data.story_light', null)->assertJsonPath('data.story_dark.width', 12);
        $this->assertDatabaseCount('branding_images', 5);
    }

    public function test_invalid_content_mismatched_extensions_and_oversized_images_never_write(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/foundation/branding/story_light', [])->assertUnprocessable()
            ->assertJsonValidationErrors('image');
        foreach ([
            UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'),
            UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>'),
            UploadedFile::fake()->createWithContent('truncated.png', "\x89PNG\r\n\x1a\n"),
            $this->image('mismatched.jpg'),
            $this->image('executable.php'),
            UploadedFile::fake()->create('too-large.png', 5121, 'image/png'),
            $this->image('wide.png', 4097, 1),
            $this->image('tall.png', 1, 4097),
        ] as $file) {
            $this->upload('story_light', $file)->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertDatabaseCount('branding_images', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unknown_slots_versions_and_client_paths_are_rejected_or_ignored(): void
    {
        $this->actingAsAdmin();
        $this->upload('unknown')->assertNotFound();
        $this->deleteJson('/api/foundation/branding/unknown')->assertNotFound();
        $this->get('/api/branding/images/story_light/not-a-version')->assertNotFound();
        $this->get('/api/branding/images/unknown/00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->get('/api/branding/images/story_light/..%2F..%2F.env')->assertNotFound();
        $this->post('/api/foundation/branding/logo_dark', ['image' => $this->image(),
            'storage_path' => '../../.env', 'slot' => 'story_light', 'version' => '../../private'],
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.story_light', null);
        $this->assertDatabaseCount('branding_images', 1);
        $this->assertTrue(app(BrandingImages::class)->safePath(DB::table('branding_images')->value('storage_path')));
        $this->assertFalse(app(BrandingImages::class)->safePath('../../.env'));
        $this->assertFalse(app(BrandingImages::class)->safePath('branding/another-database/test.png'));
    }

    public function test_audit_failure_rolls_back_replacement_and_removes_new_file(): void
    {
        $this->actingAsAdmin();
        $original = $this->upload()->assertOk()->json('data.story_light');
        $files = Storage::disk('local')->allFiles();
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('Simulated branding audit failure');
            }
        });
        $this->upload('story_light', $this->image('replacement.png', 3, 4))->assertStatus(500);
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('data.story_light.version', $original['version']);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->get($original['url'])->assertOk();
    }

    public function test_audit_failure_keeps_original_image_when_restoring_defaults(): void
    {
        $this->actingAsAdmin();
        $original = $this->upload()->assertOk()->json('data.story_light');
        $files = Storage::disk('local')->allFiles();
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new RuntimeException('Simulated branding audit failure');
            }
        });
        $this->deleteJson('/api/foundation/branding/story_light')->assertStatus(500);
        $this->getJson('/api/branding')->assertOk()->assertJsonPath('data.story_light.version', $original['version']);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }
}
