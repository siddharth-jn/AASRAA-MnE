<?php

namespace Database\Seeders;

use App\Models\ApprovalAction;
use App\Models\Centre;
use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $password = config('impact.demo_password') ?: 'AasraImpact!2026';
            $users = collect([
                ['Admin User', 'admin@aasra.demo', 'ADMIN'], ['Senior Program Lead', 'senior@aasra.demo', 'SENIOR PROGRAM LEAD'],
                ['Program Lead', 'lead@aasra.demo', 'PROGRAM LEAD'], ['Centre Coordinator', 'coordinator@aasra.demo', 'CENTRE COORDINATOR'], ['Read-only Viewer', 'viewer@aasra.demo', 'VIEWER'],
            ])->mapWithKeys(function (array $row) use ($password): array {
                $user = User::updateOrCreate(['email' => $row[1]], ['name' => $row[0], 'password' => Hash::make($password), 'role' => $row[2], 'active' => true, 'is_demo' => true]);

                return [$row[2] => $user];
            });

            $programmeRows = [
                ['Street Smart', 'Prepares out-of-school children for mainstream education through foundational learning.', 'street-smart'],
                ['Mainstreaming / SQEP', 'Supports formal education access and learning quality in partner schools.', 'mainstreaming'],
                ['Wings of Doon', 'Provides after-school education, mentoring, and academic reinforcement.', 'wings-of-doon'],
                ["Girls' Education / TITAN KANYA+", 'Supports girls through education, life skills, career guidance, and retention.', 'girls-education'],
                ['Higher Education', 'Supports access to diploma, certification, degree, and professional education.', 'higher-education-program'],
                ['SkillAbility', 'Provides vocational, employability, and enterprise training to underserved youth.', 'skillability'],
                ['Special Needs Centre', 'Provides individualized educational and developmental support.', 'special-needs-center'],
                ['Shelter Homes', 'Provides safe residential care, education, nutrition, healthcare, and holistic support.', 'shelter-homes'],
                ['Healthcare', 'Supports access to assessments, preventive care, referrals, and health education.', 'healthcare'],
                ['Nutrition', 'Provides meals, snacks, food preparation, and distribution.', 'nutrition'],
                ['Outreach', 'Connects underserved children and youth with education and skills opportunities.', 'outreach'],
            ];
            $projects = collect();
            foreach ($programmeRows as $index => $row) {
                $programme = Programme::updateOrCreate(['name' => $row[0]], ['description' => $row[1], 'source_url' => 'https://aasraatrust.org/programmes/'.$row[2].'/', 'is_demo' => true]);
                $projects->put($index, Project::updateOrCreate(['code' => sprintf('PRG-%02d', $index + 1)], ['programme_id' => $programme->id, 'name' => $row[0], 'description' => $row[1], 'lead_id' => $users['PROGRAM LEAD']->id, 'active' => true, 'is_demo' => true]));
            }

            $centreRows = [
                ['SS-ISBT', 'Street Smart, ISBT', 'Education Centre', 'Dehradun', 110], ['MLC-KB', 'Mobile Learning Centre, Kabadi Bazar', 'Mobile Learning Unit', 'Dehradun', 75], ['EC-PN', 'Education Cart, Prem Nagar', 'Community Location', 'Dehradun', 45],
                ['GPS-C2', 'Government Primary School, Chukkhuwala 2', 'Partner School', 'Dehradun', 180], ['GPS-AR', 'Government Primary School, Araghar', 'Partner School', 'Dehradun', 160], ['GUPS-RC', 'Government Upper Primary School, Rest Camp', 'Partner School', 'Dehradun', 210],
                ['NIOS-GG', 'NIOS Centre, Govindgarh', 'Education Centre', 'Dehradun', 120], ['WOD-NH', 'Wings of Doon, New Haven, Kanwali Road', 'Education Centre', 'Dehradun', 130], ['WOD-SDIC', 'Wings of Doon, Sanatan Dharm Inter College, Bannu, Racecourse', 'Partner School', 'Dehradun', 165],
                ['SNC-IN', 'Special Needs Centre, Indira Nagar', 'Education Centre', 'Dehradun', 45], ['SA-RP', 'SkillAbility Centre, Raipur', 'Training Centre', 'Dehradun', 140], ['GGIC-DI', 'GGIC Dineshpur', 'Partner School', 'Udham Singh Nagar', 320],
                ['GPS-TH', 'Government Primary School, Thangarh', 'Partner School', 'Pauri Garhwal', 95], ['BSH-AR', 'Boys’ Shelter Home, Araghar', 'Shelter Home', 'Dehradun', 70], ['GSH-RR', 'Girls’ Shelter Home, Rajpur Road', 'Shelter Home', 'Dehradun', 65],
                ['GGIC-SU', 'GGIC Sultanpur', 'Partner School', 'Udham Singh Nagar', 280], ['GGIC-MG', 'GGIC Mahuakhera Ganj', 'Partner School', 'Udham Singh Nagar', 300], ['GGIC-GA', 'GGIC Gadarpur', 'Partner School', 'Udham Singh Nagar', 290],
                ['REG-DDN', 'Higher Education — Dehradun', 'Programme Reporting Region', 'Dehradun', null], ['REG-USN', 'Higher Education — Udham Singh Nagar', 'Programme Reporting Region', 'Udham Singh Nagar', null],
            ];
            $centres = collect();
            foreach ($centreRows as $index => $row) {
                $centres->put($index, Centre::updateOrCreate(['code' => $row[0]], ['name' => $row[1], 'type' => $row[2], 'region' => $row[3], 'capacity' => $row[4], 'source_url' => $index < 18 ? 'https://aasraatrust.org/' : 'https://aasraatrust.org/programmes/higher-education-program/', 'provenance' => 'public name; synthetic reporting configuration', 'active' => true, 'is_demo' => true]));
            }

            $map = [[0, 1, 2], [3, 4, 5, 6, 12], [7, 8], [11, 15, 16, 17], [18, 19], [10], [9], [13, 14], [0, 1, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17], [0, 1, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17], [0, 1, 2, 3, 4, 5, 10, 11, 12, 15, 16, 17]];
            foreach ($map as $projectIndex => $centreIndexes) {
                foreach ($centreIndexes as $centreIndex) {
                    $projects[$projectIndex]->centres()->syncWithoutDetaching([$centres[$centreIndex]->id => ['is_demo' => true, 'provenance' => 'synthetic programme-centre assignment']]);
                }
            }
            foreach ($users as $user) {
                $user->projects()->syncWithPivotValues($projects->pluck('id'), ['is_demo' => true]);
                $user->centres()->syncWithPivotValues($centres->pluck('id'), ['is_demo' => true]);
            }

            $templates = [['Active staff', 'Input', 'people', 'LATEST SNAPSHOT', 'SUM'], ['Current participants', 'Input', 'people', 'LATEST SNAPSHOT', 'SUM'], ['Sessions conducted', 'Activity', 'sessions', 'SUM', 'SUM'], ['Engagement activities', 'Activity', 'activities', 'SUM', 'SUM'], ['Participants completing support', 'Output', 'people', 'SUM', 'SUM'], ['Performance rate', 'Outcome', '%', 'RATIO', 'RATIO']];
            $periods = collect(range(4, 9))->map(fn (int $month): string => "2026-{$month}-01");
            foreach ($projects as $projectIndex => $project) {
                foreach ($templates as $indicatorIndex => $template) {
                    $name = match ([$projectIndex, $indicatorIndex]) {
                        [0, 2] => 'Literacy Sessions Conducted', [5, 2] => 'Vocational Training Sessions', [9, 2] => 'Meals Distributed', [7, 1] => 'Current Occupancy', [9, 1] => 'Current Beneficiary Strength', [9, 5] => 'Meal Coverage Rate', default => $template[0]
                    };
                    $indicator = IndicatorDefinition::updateOrCreate(['code' => sprintf('P%02d-I%02d', $projectIndex + 1, $indicatorIndex + 1)], [
                        'project_id' => $project->id, 'name' => $name, 'description' => "Demo {$template[1]} indicator for {$project->name}.", 'category' => $template[1], 'unit' => $template[2],
                        'data_type' => $template[3] === 'RATIO' ? 'ratio' : 'integer', 'frequency' => 'monthly', 'time_aggregation' => $template[3], 'centre_aggregation' => $template[4], 'direction' => 'higher',
                        'min_value' => 0, 'max_value' => $template[3] === 'RATIO' ? 100 : null, 'precision' => $template[3] === 'RATIO' ? 1 : 0, 'evidence_required' => $indicatorIndex === 2,
                        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'effective_date' => '2026-04-01', 'active' => true, 'is_demo' => true,
                    ]);
                    $definitionVersion = $indicator->versions()->firstOrCreate(['version' => 1], ['definition' => $indicator->attributesToArray(), 'is_demo' => true]);
                    foreach ($project->centres as $centreOffset => $centre) {
                        $assignment = IndicatorAssignment::updateOrCreate(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id], ['required' => true, 'is_demo' => true]);
                        foreach ($periods as $periodOffset => $period) {
                            $base = 8 + (($projectIndex + 1) * 3) + (($centreOffset + 1) * 4) + $indicatorIndex * 5;
                            $target = $indicatorIndex === 5 ? 82 : $base + 5;
                            IndicatorTarget::updateOrCreate(['indicator_assignment_id' => $assignment->id, 'period' => $period, 'period_type' => 'monthly'], [
                                'period_end' => date('Y-m-t', strtotime($period)), 'target_type' => 'numeric', 'value' => $target, 'active' => true, 'is_demo' => true,
                            ]);
                            if (($projectIndex + $centreOffset + $indicatorIndex + $periodOffset) % 19 === 0) {
                                continue;
                            }
                            $entry = IndicatorEntry::firstOrCreate(['indicator_assignment_id' => $assignment->id, 'period' => $period], ['status' => 'DRAFT', 'is_demo' => true]);
                            if ($entry->revisions()->exists()) {
                                continue;
                            }
                            $selector = ($projectIndex * 17 + $centreOffset * 7 + $indicatorIndex * 3 + $periodOffset) % 23;
                            $status = $selector === 0 ? 'SUBMITTED' : ($selector === 1 ? 'RETURNED FOR CORRECTION' : ($selector === 2 ? 'REJECTED' : 'APPROVED'));
                            $numerator = $indicatorIndex === 5 ? min($target + 10, 68 + (($centreOffset + $periodOffset + $projectIndex) % 24)) : null;
                            $denominator = $indicatorIndex === 5 ? 100 : null;
                            $value = $indicatorIndex === 5 ? $numerator : max(0, $base + (($periodOffset * 2 + $centreOffset) % 9) - 3);
                            $revision = $entry->revisions()->create(['indicator_definition_version_id' => $definitionVersion->id, 'user_id' => $users['PROGRAM LEAD']->id, 'version' => 1, 'status' => $status, 'value_state' => 'reported', 'numeric_value' => $value, 'numerator' => $numerator, 'denominator' => $denominator, 'notes' => 'Synthetic demonstration record.', 'submitted_at' => "$period 10:00:00", 'approved_at' => $status === 'APPROVED' ? "$period 15:00:00" : null, 'is_demo' => true]);
                            $entry->update(['current_revision_id' => $revision->id, 'approved_revision_id' => $status === 'APPROVED' ? $revision->id : null, 'status' => $status]);
                            if ($status !== 'SUBMITTED') {
                                ApprovalAction::create(['entry_revision_id' => $revision->id, 'user_id' => $users['SENIOR PROGRAM LEAD']->id, 'action' => $status, 'comment' => $status === 'APPROVED' ? 'Demo review completed.' : 'Demo record requires clarification.', 'is_demo' => true]);
                            }
                            if ($indicator->evidence_required && $periodOffset % 2 === 0) {
                                $path = "evidence/demo/{$entry->id}.txt";
                                Storage::disk('local')->put($path, "AASRA IMPACT DEMO EVIDENCE\nSynthetic, non-sensitive record for {$indicator->name}.\n");
                                EvidenceFile::create(['entry_revision_id' => $revision->id, 'user_id' => $users['PROGRAM LEAD']->id, 'path' => $path, 'original_name' => "demo-evidence-{$entry->id}.txt", 'mime_type' => 'text/plain', 'size' => Storage::disk('local')->size($path), 'is_demo' => true]);
                            }
                        }
                    }
                }
            }
        });
    }
}
