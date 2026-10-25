<?php

namespace Tests\Feature\Feedback;

use App\Models\FeedbackAutomationRun;
use App\Models\FeedbackProject;
use App\Models\FeedbackReport;
use App\Services\CodexFeedbackCodingAgent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class CodexFeedbackCodingAgentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/feedback-codex-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/app/.git');
        config([
            'feedback.automation.workspace_root' => $this->workspace,
            'feedback.automation.codex_binary' => 'codex',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    /** @return array{0: FeedbackReport, 1: FeedbackAutomationRun} */
    private function reportAndRun(): array
    {
        $project = FeedbackProject::factory()->create([
            'automation_enabled' => true,
            'repository_path' => $this->workspace.'/app',
        ]);
        $report = FeedbackReport::factory()->for($project)->create(['title' => '儲存失敗', 'description' => '按下儲存沒反應']);
        $report->setRelation('feedbackProject', $project);

        return [$report, FeedbackAutomationRun::factory()->for($report)->create()];
    }

    /**
     * 只有執行指令、不要求它產出結果檔；結果檔不存在時 resolve() 會丟 RuntimeException，測試只看指令。
     */
    private function runCodex(): void
    {
        Process::fake(['*' => Process::result('', '', 0)]);

        [$report, $run] = $this->reportAndRun();

        try {
            app(CodexFeedbackCodingAgent::class)->resolve($report, $run);
        } catch (RuntimeException $exception) {
            // 只放過「沒有結果檔」這個預期錯誤；其他例外代表前置條件有問題，要讓測試失敗。
            if ($exception->getMessage() !== 'Codex 未產生結構化處理結果。') {
                throw $exception;
            }
        }
    }

    public function test_codex_is_not_given_both_sandbox_flag_and_approve_for_me(): void
    {
        $this->runCodex();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            // codex-cli 會拒絕 --sandbox 與 --approve-for-me 同時出現（實測：exit 2，cannot be used with）。
            // 沙盒參數有 --sandbox、--sandbox=…、-s、-s…（合併寫法）等形式，全部都要擋。
            $hasSandboxFlag = collect($command)->contains(
                fn (string $argument): bool => str_starts_with($argument, '--sandbox') || str_starts_with($argument, '-s'),
            );

            return in_array('--approve-for-me', $command, true) && ! $hasSandboxFlag;
        });
    }

    public function test_codex_runs_exec_with_structured_output_and_prompt_on_stdin(): void
    {
        $this->runCodex();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;
            $schemaIndex = array_search('--output-schema', $command, true);
            $resultIndex = array_search('--output-last-message', $command, true);

            return array_slice($command, 0, 2) === ['codex', 'exec']
                && in_array('--json', $command, true)
                && in_array('--ephemeral', $command, true)
                && in_array('--approve-for-me', $command, true)
                && str_ends_with($command[$schemaIndex + 1], 'feedback/codex-result.schema.json')
                && str_ends_with($command[$resultIndex + 1], '-result.json')
                && $command[array_key_last($command)] === '-';
        });
    }
}
