<?php

use App\Mail\AccountCreatedMail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->staff = User::factory()->staff()->create();
    loginAs($this->staff);
});

test('プロジェクトを作成できる(キーは大文字に揃える)', function () {
    $customer = User::factory()->customer()->create();

    $this->post('/staff/projects', [
        'key' => 'web',
        'name' => 'コーポレートサイト保守',
        'members' => [$this->staff->id, $customer->id],
    ])->assertRedirect('/staff/projects/WEB/edit');

    $project = Project::where('key', 'WEB')->firstOrFail();
    expect($project->members()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->staff->id, $customer->id])->sort()->values()->all());
});

test('プロジェクトキーは形式チェックと重複チェックがある', function (string $key) {
    Project::factory()->create(['key' => 'DUP']);

    $this->post('/staff/projects', ['key' => $key, 'name' => 'テスト'])
        ->assertSessionHasErrors('key');
})->with(['1AB', 'A', 'TOOLONGKEY1', 'AB-C', 'dup']);

test('プロジェクトキーは更新できない(名前と説明だけ更新される)', function () {
    $project = Project::factory()->create(['key' => 'ABC']);

    $this->put('/staff/projects/ABC', ['key' => 'XYZ', 'name' => '新しい名前'])->assertRedirect('/staff/projects/ABC/edit');
    expect($project->fresh()->key)->toBe('ABC')->and($project->fresh()->name)->toBe('新しい名前');
});

test('メンバーを追加・削除できる', function () {
    $project = Project::factory()->create(['key' => 'ABC']);
    $customer = User::factory()->customer()->create();

    $this->post('/staff/projects/ABC/members', ['user_id' => $customer->id])->assertRedirect('/staff/projects/ABC/edit');
    expect($project->members()->whereKey($customer->id)->exists())->toBeTrue();

    $this->delete("/staff/projects/ABC/members/{$customer->id}")->assertRedirect('/staff/projects/ABC/edit');
    expect($project->members()->whereKey($customer->id)->exists())->toBeFalse();
});

test('プロジェクトを削除するとお客様からも見えなくなる', function () {
    $project = Project::factory()->create(['key' => 'ABC']);
    $customer = User::factory()->customer()->create();
    $project->members()->attach($customer->id);

    $this->delete('/staff/projects/ABC')->assertRedirect('/staff/projects');
    expect($project->fresh()->trashed())->toBeTrue();

    loginAs($customer);
    $this->get('/projects/ABC/issues')->assertNotFound();
});

test('お客様ユーザーを作成するとログイン案内メールが届く', function () {
    $project = Project::factory()->create();

    $this->post('/staff/users', [
        'role' => 'customer',
        'name' => '山田 花子',
        'email' => 'Hanako@Example.com',
        'company_name' => '株式会社サンプル',
        'projects' => [$project->id],
        'send_welcome_mail' => '1',
    ])->assertRedirect('/staff/users');

    $user = User::where('email', 'hanako@example.com')->firstOrFail();
    expect($user->isCustomer())->toBeTrue()
        ->and($user->company_name)->toBe('株式会社サンプル')
        ->and($user->projects()->pluck('projects.id')->all())->toBe([$project->id]);

    Mail::assertSent(AccountCreatedMail::class, function (AccountCreatedMail $mail) {
        return $mail->hasTo('hanako@example.com') && str_contains($mail->render(), '/login');
    });
});

test('案内メールを送らない指定もできる。社内メンバーには会社名を保存しない', function () {
    $this->post('/staff/users', [
        'role' => 'staff',
        'name' => '社内 花子',
        'email' => 'staff2@example.com',
        'company_name' => '入力されても無視',
    ])->assertRedirect('/staff/users');

    expect(User::where('email', 'staff2@example.com')->first()->company_name)->toBeNull();
    Mail::assertNothingSent();
});

test('メールアドレスの重複は登録できない(大文字小文字を区別しない)', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->post('/staff/users', ['role' => 'customer', 'name' => 'x', 'email' => 'DUP@example.com'])
        ->assertSessionHasErrors('email');
});

test('ユーザーを無効化できるが、自分自身は無効化できない', function () {
    $customer = User::factory()->customer()->create();

    $this->put("/staff/users/{$customer->id}", [
        'role' => 'customer', 'name' => $customer->name, 'email' => $customer->email, 'is_active' => '0',
    ])->assertRedirect('/staff/users');
    expect($customer->fresh()->is_active)->toBeFalse();

    $this->put("/staff/users/{$this->staff->id}", [
        'role' => 'staff', 'name' => $this->staff->name, 'email' => $this->staff->email, 'is_active' => '0',
    ])->assertSessionHasErrors('is_active');
    expect($this->staff->fresh()->is_active)->toBeTrue();
});

test('管理画面が表示できる', function () {
    Project::factory()->create(['key' => 'ABC']);
    $user = User::factory()->customer()->create();

    $this->get('/staff/projects')->assertOk();
    $this->get('/staff/projects/create')->assertOk();
    $this->get('/staff/projects/ABC/edit')->assertOk();
    $this->get('/staff/users')->assertOk()->assertSee($user->name);
    $this->get('/staff/users/create')->assertOk();
    $this->get("/staff/users/{$user->id}/edit")->assertOk();
});
