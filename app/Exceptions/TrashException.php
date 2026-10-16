<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ゴミ箱の操作ができない場合(親のプロジェクト・課題が削除されたままで元に戻せない等)。
 * メッセージはそのまま利用者に表示する
 */
class TrashException extends RuntimeException {}
