<?php

namespace Database\Seeders;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\UserRole;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * 開発・デモ用のサンプルデータ(本番では実行しないこと)
 * php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $staff = User::where('role', UserRole::Staff->value)->firstOrFail();
        $staff2 = User::firstOrCreate(
            ['email' => 'staff2@example.com'],
            ['name' => '佐藤 開発', 'role' => UserRole::Staff->value, 'is_active' => true]
        );
        $customer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            ['name' => '山田 花子', 'role' => UserRole::Customer->value, 'company_name' => 'サンプル商事株式会社', 'is_active' => true]
        );
        $otherCustomer = User::firstOrCreate(
            ['email' => 'other@example.com'],
            ['name' => '鈴木 一郎', 'role' => UserRole::Customer->value, 'company_name' => '別会社株式会社', 'is_active' => true]
        );

        $project = Project::firstOrCreate(
            ['key' => 'DEMO'],
            ['name' => 'サンプル商事 Webサイト保守', 'description' => 'サンプル商事様のコーポレートサイト・会員サイトの保守案件です。']
        );
        $project->members()->syncWithoutDetaching([$staff->id, $customer->id]);

        $otherProject = Project::firstOrCreate(
            ['key' => 'OTHER'],
            ['name' => '別会社 業務システム', 'description' => null]
        );
        $otherProject->members()->syncWithoutDetaching([$staff2->id, $otherCustomer->id]);

        if ($project->issues()->exists()) {
            return;
        }

        $samples = [
            ['ログイン後にトップページが真っ白になる', IssueType::Bug, IssuePriority::High, IssueStatus::InProgress, $staff],
            ['お問い合わせフォームの確認メールが届かない', IssueType::Bug, IssuePriority::High, IssueStatus::Open, null],
            ['会員一覧をCSVでダウンロードしたい', IssueType::Request, IssuePriority::Normal, IssueStatus::Open, $staff2],
            ['スマホで表示するとメニューが重なる', IssueType::Bug, IssuePriority::Normal, IssueStatus::Resolved, $staff],
            ['ニュースの公開予約はできますか？', IssueType::Question, IssuePriority::Low, IssueStatus::Closed, $staff],
        ];

        foreach ($samples as $i => [$title, $type, $priority, $status, $assignee]) {
            $issue = Issue::factory()->create([
                'project_id' => $project->id,
                'title' => $title,
                'description' => $type->descriptionTemplate() ?: 'サンプルの課題です。',
                'type' => $type,
                'priority' => $priority,
                'status' => $status,
                'reporter_id' => $customer->id,
                // 2件目は電話で受けた内容を社内メンバーが代わりに登録した例(代理起票)
                'registered_by' => $i === 1 ? $staff->id : null,
                'assignee_id' => $assignee?->id,
                'due_date' => $i < 2 ? today()->addDays($i * 3 - 1) : null,
                'closed_at' => $status === IssueStatus::Closed ? now() : null,
            ]);

            if ($status !== IssueStatus::Open) {
                Comment::create([
                    'issue_id' => $issue->id,
                    'user_id' => $staff->id,
                    'body' => 'ご連絡ありがとうございます。確認いたします。',
                    'change_log' => [['field' => 'status', 'from' => 'open', 'to' => $status->value]],
                ]);
            }

            // 1件目は記入例として、詳細とスクリーンショットを付けておく
            if ($i === 0) {
                $issue->update(['description' => "【発生した画面・URL】\nhttps://www.example.com/mypage\n\n【操作手順】\n1. ログイン画面でメールアドレスとパスワードを入力\n2. 「ログイン」ボタンを押す\n\n【期待する動作】\nマイページが表示される\n\n【実際の動作】\n画面が真っ白になり、何も表示されない（スクリーンショット添付）\n\n【発生日時・頻度】\n本日10時頃から、毎回発生\n\n【端末・ブラウザ】\nWindows 11 / Chrome"]);
                $this->attachSampleScreenshot($issue, $customer);
            }
        }

        Issue::factory()->create([
            'project_id' => $otherProject->id,
            'title' => '（別プロジェクト）帳票の金額がずれる',
            'reporter_id' => $otherCustomer->id,
        ]);
    }

    /**
     * 画面のスクリーンショット風のサンプル画像を作って添付する(GDで描画)
     */
    private function attachSampleScreenshot(Issue $issue, User $user): void
    {
        $image = imagecreatetruecolor(1280, 800);
        $white = imagecolorallocate($image, 255, 255, 255);
        $gray = imagecolorallocate($image, 230, 233, 237);
        $dark = imagecolorallocate($image, 52, 58, 64);
        $red = imagecolorallocate($image, 220, 53, 69);

        // ブラウザ風の枠と、真っ白な本文エリア
        imagefill($image, 0, 0, $white);
        imagefilledrectangle($image, 0, 0, 1279, 70, $gray);
        imagefilledrectangle($image, 120, 20, 900, 50, $white);
        imagestring($image, 5, 135, 27, 'https://www.example.com/mypage', $dark);
        imagerectangle($image, 380, 300, 900, 500, $red);
        imagestring($image, 5, 470, 390, '(blank page after login)', $red);

        $path = tempnam(sys_get_temp_dir(), 'mushitori');
        imagepng($image, $path);

        app(AttachmentService::class)->store(
            new UploadedFile($path, 'screenshot_blank_page.png', 'image/png', null, true),
            $issue,
            $user
        );
        @unlink($path);
    }
}
