# LINE通知の設定

シフト提出依頼の作成・修正時はLINE連携済みの有効なキャスト全員、催促時は未提出者のみ、確定時はチェックを入れた場合にキャスト全員へ通知します。部分確定時は選択範囲を通知します。通知本文には対象期間、提出期限（依頼・催促時）、お知らせ、アプリのURLを含みます。

## 設定

1. LINE公式アカウントのMessaging APIを有効にします。LINEログインと**同じプロバイダー**にMessaging APIチャネルを作成してください。同じプロバイダーの場合にログイン時のユーザーIDを利用できます。
2. チャネルアクセストークンを発行し、`api/.env` の `LINE_MESSAGING_CHANNEL_ACCESS_TOKEN` に設定します。フロントエンドには設定しません。`FRONTEND_URL` はキャストがアクセスできる公開URLにします。
3. キャストに公式アカウントの友だち追加を案内してください。ログインだけでは通知を受信できません。ブロック中のユーザーには届きません。
4. `docker compose exec app php artisan migrate` と `docker compose exec app php artisan config:clear` を実行します。
5. Docker Composeの専用ワーカーを起動します。`restart: unless-stopped` により、異常終了時とDockerの再起動時に自動起動します。通知キューのDBにも同じ再起動設定を適用しています。

```sh
docker compose up -d line-worker
```

手動で `docker compose stop` した場合は自動復帰しないため、`docker compose up -d` で再開してください。PC再起動時はDocker Desktop自体も起動している必要があります。コードや `.env` の変更後は `docker compose restart line-worker` を実行します。稼働状況は `docker compose ps line-worker`、ログは `docker compose logs --tail 50 line-worker` で確認できます。

アプリと通知キューは同じDB接続を使い、保存とジョブ登録を同じトランザクションで処理します。ワーカー未起動の場合は通知が待機します。トークン未設定の場合は保存を継続し、画面に未設定と表示します。

## 送信の確認・障害対応

画面の「受け付けました」はキューへの登録件数であり、配信完了件数ではありません。LINE未連携者は対象外です。LINE APIが受け付けても、友だち未追加やブロックなどで実際には届かない場合があります。Messaging APIの送信枠・料金は公式アカウントのプランで確認してください。

通信エラー・HTTP 429・5xxは同じリトライキーで最大4回試行します。他の4xxは失敗扱いにします。`php artisan queue:failed` で確認し、原因解消後に `php artisan queue:retry <ID>` で再試行できます。重複送信を防ぐため、登録から23時間以上経過したジョブは送信せず失敗にします。催促は日本時間で提出期限の翌日から利用でき、依頼ごとに5分間隔です。期限切れの依頼も未確定の間は提出状況画面に表示します。

実機確認はテスト用キャストで友だち追加・ログイン後に行い、依頼作成、修正、未提出者への催促、部分確定、通知チェックOFFを確認してください。

公式仕様:
- https://developers.line.biz/ja/docs/messaging-api/sending-messages/
- https://developers.line.biz/ja/docs/messaging-api/getting-user-ids/
- https://developers.line.biz/ja/docs/messaging-api/retrying-api-request/
