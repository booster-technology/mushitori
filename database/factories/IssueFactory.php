<?php

namespace Database\Factories;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->realText(30),
            'description' => fake()->realText(200),
            'type' => IssueType::Bug,
            'priority' => IssuePriority::Normal,
            'status' => IssueStatus::Open,
            'reporter_id' => User::factory(),
            'assignee_id' => null,
            'due_date' => null,
        ];
    }

    /**
     * 課題番号はプロジェクトの連番から採番する(IssueServiceと同じ考え方)
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Issue $issue) {
            if ($issue->number === null) {
                $project = Project::findOrFail($issue->project_id);
                $issue->number = $project->next_issue_number;
                $project->increment('next_issue_number');
            }
        });
    }
}
