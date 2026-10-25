<?php

namespace App\Http\Controllers;

/**
 * HTTPリクエストを処理するControllerの基底クラス。
 * 入力の受け取り・認可・検証・レスポンス返却を担当する。
 * 更新はUsecaseでDomain生成・業務検証→Repositoryで永続化する。
 * 一覧などの参照はQueryServiceに委譲する。
 */
abstract class Controller
{
    //
}
