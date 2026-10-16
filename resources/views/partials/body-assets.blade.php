{{--
    JavaScript(すべて同梱。CDNは使わない)。読み込み順は Bootstrap → app.js(Alpine のコンポーネントの登録)→ Alpine.js(defer)。
    Alpine.js は CSP対応版(eval を使わない)なので、Content-Security-Policy を script-src 'self' のまま緩めずに動く
--}}
<script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}?v=5.3.3"></script>
<script src="{{ asset_v('js/app.js') }}"></script>
<script defer src="{{ asset('vendor/alpinejs/alpine-csp.min.js') }}?v=3.17.4"></script>
