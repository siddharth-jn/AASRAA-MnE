<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('VIEWER');
            $t->boolean('active')->default(true);
            $t->boolean('is_demo')->default(false)->index();
        });
        Schema::create('programmes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('description');
            $t->string('source_url')->nullable();
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description');
            $t->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('active')->default(true);
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('centres', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('type');
            $t->string('region');
            $t->unsignedInteger('capacity')->nullable();
            $t->string('source_url')->nullable();
            $t->string('provenance')->default('operational');
            $t->boolean('active')->default(true);
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('project_centres', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('centre_id')->constrained()->restrictOnDelete();
            $t->boolean('is_demo')->default(false);
            $t->string('provenance')->default('operational');
            $t->unique(['project_id', 'centre_id']);
        });
        foreach (['project', 'centre'] as $scope) {
            Schema::create($scope.'_user_assignments', function (Blueprint $t) use ($scope) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId($scope.'_id')->constrained()->restrictOnDelete();
                $t->boolean('is_demo')->default(false);
                $t->unique(['user_id', $scope.'_id']);
            });
        }
        Schema::create('indicator_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description');
            $t->string('category');
            $t->string('unit');
            $t->string('data_type');
            $t->string('frequency')->default('monthly');
            $t->string('time_aggregation');
            $t->string('centre_aggregation');
            $t->string('direction')->default('higher');
            $t->decimal('min_value', 18, 4)->nullable();
            $t->decimal('max_value', 18, 4)->nullable();
            $t->unsignedTinyInteger('precision')->default(2);
            $t->unsignedInteger('max_length')->default(2000);
            $t->json('options')->nullable();
            $t->date('effective_date')->default('2026-04-01');
            $t->boolean('evidence_required')->default(false);
            $t->json('allowed_extensions')->nullable();
            $t->unsignedInteger('max_file_kb')->default(5120);
            $t->unsignedTinyInteger('max_files')->default(5);
            $t->boolean('active')->default(true);
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('indicator_definition_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('indicator_definition_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('definition');
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->unique(['indicator_definition_id', 'version'], 'indicator_version_unique');
        });
        Schema::create('indicator_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('indicator_definition_id')->constrained()->restrictOnDelete();
            $t->foreignId('centre_id')->constrained()->restrictOnDelete();
            $t->boolean('required')->default(true);
            $t->boolean('is_demo')->default(false);
            $t->unique(['indicator_definition_id', 'centre_id'], 'indicator_centre_unique');
        });
        Schema::create('indicator_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('indicator_assignment_id')->constrained()->restrictOnDelete();
            $t->date('period');
            $t->decimal('value', 18, 4);
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->unique(['indicator_assignment_id', 'period']);
        });
        Schema::create('indicator_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('indicator_assignment_id')->constrained()->restrictOnDelete();
            $t->date('period');
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->unsignedBigInteger('approved_revision_id')->nullable();
            $t->string('status')->default('DRAFT')->index();
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
            $t->unique(['indicator_assignment_id', 'period']);
            $t->index(['period', 'status']);
        });
        Schema::create('entry_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('indicator_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('indicator_definition_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status')->default('DRAFT');
            $t->string('value_state')->default('reported');
            $t->decimal('numeric_value', 18, 4)->nullable();
            $t->decimal('numerator', 18, 4)->nullable();
            $t->decimal('denominator', 18, 4)->nullable();
            $t->decimal('weight', 18, 4)->nullable();
            $t->text('text_value')->nullable();
            $t->date('date_value')->nullable();
            $t->boolean('boolean_value')->nullable();
            $t->json('json_value')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->unique(['indicator_entry_id', 'version']);
        });
        Schema::table('indicator_entries', function (Blueprint $t) {
            $t->foreign('current_revision_id')->references('id')->on('entry_revisions')->restrictOnDelete();
            $t->foreign('approved_revision_id')->references('id')->on('entry_revisions')->restrictOnDelete();
        });
        Schema::create('evidence_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime_type');
            $t->unsignedBigInteger('size');
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
        });
        Schema::create('approval_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action');
            $t->text('comment')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->string('filename');
            $t->string('path');
            $t->string('fingerprint');
            $t->json('columns');
            $t->json('mapping')->nullable();
            $t->string('status')->default('uploaded');
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->unique(['project_id', 'fingerprint']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('source');
            $t->json('normalized')->nullable();
            $t->string('status')->default('review');
            $t->text('error')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->unique(['import_batch_id', 'row_number']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->json('changes')->nullable();
            $t->boolean('is_demo')->default(false)->index();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('indicator_entries', function (Blueprint $t) {
            $t->dropForeign(['current_revision_id']);
            $t->dropForeign(['approved_revision_id']);
        });
        foreach (['audit_logs', 'import_rows', 'import_batches', 'notifications', 'approval_actions', 'evidence_files', 'entry_revisions', 'indicator_entries', 'indicator_targets', 'indicator_assignments', 'indicator_definition_versions', 'indicator_definitions', 'centre_user_assignments', 'project_user_assignments', 'project_centres', 'centres', 'projects', 'programmes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active', 'is_demo']));
    }
};
