<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDirectoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_filtered_by_the_local_programme_relationship(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        [$streetSmart, $streetSmartProject] = $this->createProgramme('Street Smart', 'SS');
        [, $niosProject] = $this->createProgramme('NIOS', 'NIOS');
        $chukkuwala = $this->createProject('SS-CHUK', 'Chukkuwala', 501, 'Incorrect imported programme');
        $govindgarh = $this->createProject('NIOS-GOV', 'Govindgarh', 601, 'Street Smart');
        $streetSmartProject->centres()->attach($chukkuwala);
        $niosProject->centres()->attach($govindgarh);

        $response = $this->actingAs($administrator)->get(route('projects.index', [
            'programme' => $streetSmartProject->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('All programmes')
            ->assertSee('Type a project name, code, or source project ID')
            ->assertSee('Chukkuwala')
            ->assertSee($streetSmart->name)
            ->assertSee(route('projects.show', ['centre' => $chukkuwala, 'project' => $streetSmartProject->id]))
            ->assertDontSee('Govindgarh')
            ->assertDontSee('Incorrect imported programme');
        $response->assertViewHas('centres', fn ($projects): bool => $projects->count() === 1 && $projects->first()->is($chukkuwala));
    }

    public function test_projects_can_be_searched_by_name_code_or_source_project_id(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        [, $programme] = $this->createProgramme('Street Smart', 'SS');
        $chukkuwala = $this->createProject('SS-CHUK', 'Chukkuwala Learning Centre', 501, 'Street Smart');
        $programme->centres()->attach($chukkuwala);

        foreach (['Chukkuwala', 'SS-CHUK', '501'] as $search) {
            $this->actingAs($administrator)
                ->get(route('projects.index', ['q' => $search]))
                ->assertOk()
                ->assertSee('Chukkuwala Learning Centre')
                ->assertViewHas('centres', fn ($projects): bool => $projects->count() === 1 && $projects->first()->is($chukkuwala));
        }
    }

    public function test_project_filters_are_preserved_in_pagination_links(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        [, $programme] = $this->createProgramme('Street Smart', 'SS');

        foreach (range(1, 16) as $projectNumber) {
            $project = $this->createProject(
                sprintf('SS-%02d', $projectNumber),
                sprintf('Street Smart Project %02d', $projectNumber),
                700 + $projectNumber,
                'Legacy source value',
            );
            $programme->centres()->attach($project);
        }

        $response = $this->actingAs($administrator)->get(route('projects.index', [
            'programme' => $programme->id,
            'q' => 'Street Smart',
        ]));

        $response->assertOk()->assertViewHas('centres', function ($projects) use ($programme): bool {
            $nextPageUrl = $projects->nextPageUrl();

            return $nextPageUrl !== null
                && str_contains($nextPageUrl, 'programme='.$programme->id)
                && str_contains($nextPageUrl, 'q=Street%20Smart');
        });
    }

    /** @return array{Programme, Project} */
    private function createProgramme(string $name, string $code): array
    {
        $programme = Programme::query()->create([
            'name' => $name,
            'description' => $name.' catalogue record.',
        ]);
        $reportingProgramme = Project::query()->create([
            'programme_id' => $programme->id,
            'code' => $code,
            'name' => $name,
            'description' => $name.' reporting programme.',
            'active' => true,
        ]);

        return [$programme, $reportingProgramme];
    }

    private function createProject(string $code, string $name, int $sourceProjectId, string $sourceProgramme): Centre
    {
        return Centre::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => 'Education Centre',
            'region' => 'Dehradun',
            'source_project_id' => $sourceProjectId,
            'source_program' => $sourceProgramme,
            'source_status' => 'Active',
            'active' => true,
        ]);
    }
}
