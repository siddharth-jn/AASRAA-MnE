<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentFoldersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_document_folder_structure(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('MOU &amp; Proposals', false)
            ->assertSee('Document libraries')
            ->assertSee('Finance');

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'mou-proposals']))
            ->assertOk()
            ->assertSee('Due Diligence')
            ->assertSee('Grant Letter');

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'finance']))
            ->assertOk()
            ->assertSee('Utilization Certificates')
            ->assertSee('Audited Financial Reports');
    }

    public function test_non_admin_cannot_access_or_upload_documents(): void
    {
        Storage::fake('local');
        $programmeLead = User::factory()->create(['role' => 'PROGRAM LEAD']);

        $this->actingAs($programmeLead)->get(route('documents.index'))->assertForbidden();
        $this->actingAs($programmeLead)->post(route('documents.upload', ['group' => 'mou-proposals']), [
            'folder' => 'mou',
            'files' => [UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf')],
        ])->assertForbidden();
        $this->actingAs($programmeLead)->post(route('documents.folders.store', ['group' => 'finance']), [
            'folder_name' => 'Annual reports',
        ])->assertForbidden();
        $this->actingAs($programmeLead)->delete(route('documents.folders.destroy', ['group' => 'finance']), [
            'folder' => 'other',
        ])->assertForbidden();
    }

    public function test_admin_can_create_and_open_a_document_folder(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->post(route('documents.folders.store', ['group' => 'finance']), [
                'folder_name' => 'Annual reports 2026',
            ])
            ->assertRedirect(route('documents.index', ['group' => 'finance']));

        Storage::disk('local')->assertExists('document-folders/finance/annual-reports-2026');

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'finance']))
            ->assertOk()
            ->assertSee('Annual Reports 2026');
    }

    public function test_admin_can_upload_list_and_download_a_private_document(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)->post(route('documents.upload', ['group' => 'mou-proposals']), [
            'folder' => 'mou',
            'files' => [UploadedFile::fake()->create('Signed MOU.pdf', 20, 'application/pdf')],
        ])->assertRedirect()->assertSessionHas('success');

        $storedFile = collect(Storage::disk('local')->files('document-folders/mou-proposals/mou'))->sole();
        $this->assertStringEndsWith('--Signed MOU.pdf', $storedFile);

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'mou-proposals', 'folder' => 'mou']))
            ->assertOk()
            ->assertSee('Signed MOU.pdf');

        $this->actingAs($admin)
            ->get(route('documents.download', [
                'group' => 'mou-proposals',
                'file' => basename($storedFile),
                'folder' => 'mou',
            ]))
            ->assertOk()
            ->assertDownload('Signed MOU.pdf');
    }

    public function test_admin_can_delete_a_file_without_deleting_its_folder(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);
        Storage::disk('local')->put('document-folders/finance/other/keep.pdf', 'keep');
        Storage::disk('local')->put('document-folders/finance/other/delete.pdf', 'delete');

        $this->actingAs($admin)
            ->delete(route('documents.files.destroy', ['group' => 'finance']), [
                'folder' => 'other',
                'files' => ['delete.pdf'],
            ])
            ->assertRedirect(route('documents.index', ['group' => 'finance', 'folder' => 'other']))
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing('document-folders/finance/other/delete.pdf');
        Storage::disk('local')->assertExists('document-folders/finance/other/keep.pdf');
    }

    public function test_admin_can_delete_a_non_empty_folder_and_recreate_a_configured_folder(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);
        Storage::disk('local')->put('document-folders/mou-proposals/mou/agreement.pdf', 'agreement');

        $this->actingAs($admin)
            ->delete(route('documents.folders.destroy', ['group' => 'mou-proposals']), [
                'folder' => 'mou',
            ])
            ->assertRedirect(route('documents.index', ['group' => 'mou-proposals']))
            ->assertSessionHas('success');

        Storage::disk('local')->assertMissing('document-folders/mou-proposals/mou');
        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'mou-proposals']))
            ->assertOk()
            ->assertDontSee('folder=mou', false);

        $this->actingAs($admin)
            ->post(route('documents.folders.store', ['group' => 'mou-proposals']), ['folder_name' => 'MOU'])
            ->assertRedirect(route('documents.index', ['group' => 'mou-proposals']));

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'mou-proposals']))
            ->assertOk()
            ->assertSee('folder=mou', false);
    }

    public function test_invalid_folders_and_unsupported_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)->post(route('documents.upload', ['group' => 'unknown']), [
            'folder' => 'mou',
            'files' => [UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf')],
        ])->assertNotFound();

        $this->actingAs($admin)->post(route('documents.upload', ['group' => 'finance']), [
            'folder' => 'audited-financial-reports',
            'files' => [UploadedFile::fake()->create('unsafe.exe', 20, 'application/octet-stream')],
        ])->assertSessionHasErrors('files.0');

        Storage::disk('local')->assertDirectoryEmpty('document-folders/finance/audited-financial-reports');
    }

    public function test_admin_can_create_nested_folders_and_bulk_delete_selected_files(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->post(route('documents.folders.store', ['group' => 'finance']), [
                'parent_path' => 'audited-financial-reports',
                'folder_name' => 'Financial year 2026-27',
            ])
            ->assertRedirect(route('documents.index', [
                'group' => 'finance',
                'folder' => 'audited-financial-reports',
            ]));

        $nestedFolder = 'audited-financial-reports/financial-year-2026-27';
        Storage::disk('local')->assertExists("document-folders/finance/{$nestedFolder}");
        Storage::disk('local')->put("document-folders/finance/{$nestedFolder}/keep.pdf", 'keep');
        Storage::disk('local')->put("document-folders/finance/{$nestedFolder}/delete-one.pdf", 'delete');
        Storage::disk('local')->put("document-folders/finance/{$nestedFolder}/delete-two.pdf", 'delete');

        $this->actingAs($admin)
            ->get(route('documents.index', ['group' => 'finance', 'folder' => $nestedFolder]))
            ->assertOk()
            ->assertSee('Financial Year 2026 27')
            ->assertSee('Select files')
            ->assertSee('Delete selected');

        $this->actingAs($admin)
            ->delete(route('documents.files.destroy', ['group' => 'finance']), [
                'folder' => $nestedFolder,
                'files' => ['delete-one.pdf', 'delete-two.pdf'],
            ])
            ->assertRedirect(route('documents.index', ['group' => 'finance', 'folder' => $nestedFolder]))
            ->assertSessionHas('success');

        Storage::disk('local')->assertExists("document-folders/finance/{$nestedFolder}/keep.pdf");
        Storage::disk('local')->assertMissing("document-folders/finance/{$nestedFolder}/delete-one.pdf");
        Storage::disk('local')->assertMissing("document-folders/finance/{$nestedFolder}/delete-two.pdf");
    }
}
