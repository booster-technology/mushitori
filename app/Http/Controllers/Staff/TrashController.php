<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\TrashException;
use App\Http\Controllers\Controller;
use App\Services\TrashService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * ゴミ箱(社内メンバー専用): 削除したデータの確認・元に戻す・完全に削除
 */
class TrashController extends Controller
{
    public function __construct(
        private readonly TrashService $trash,
    ) {}

    public function index(Request $request): View
    {
        $type = $this->queryString($request, 'type');
        if (! array_key_exists($type, TrashService::TYPES)) {
            $type = 'issues';
        }

        return view('staff.trash.index', [
            'type' => $type,
            'types' => TrashService::TYPES,
            'counts' => $this->trash->counts(),
            'items' => $this->trash->list($type),
            'retentionDays' => $this->trash->retentionDays(),
        ]);
    }

    /**
     * 元に戻す
     */
    public function restore(string $type, int $id): RedirectResponse
    {
        try {
            $label = $this->trash->restore($type, $id);
        } catch (TrashException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failed($e, 'ゴミ箱から元に戻せませんでした');
        }

        return back()->with('success', "「{$label}」を元に戻しました。");
    }

    /**
     * 完全に削除する(画像などの添付ファイルの実体も消す。元に戻せない)
     */
    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        try {
            $label = $this->trash->purge($type, $id, $request->user());
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failed($e, 'ゴミ箱から完全に削除できませんでした');
        }

        return back()->with('success', "「{$label}」を完全に削除しました。");
    }
}
