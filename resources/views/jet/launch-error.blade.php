<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kadi Jet</title>
<style>
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0d1117; color: #e6edf3; font: 16px/1.5 system-ui, sans-serif; }
main { padding: 24px; text-align: center; }
a { display: inline-block; margin-top: 16px; padding: 10px 20px; border-radius: 8px; background: #e6edf3; color: #0d1117; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<main>
<p>This game session could not start.</p>
<a href="https://kadi.online" target="_top">Return to Kadi</a>
</main>
<script>
try { window.parent.postMessage({ type: 'kadi-launch-error', code: @json($code) }, '*'); } catch (e) {}
</script>
</body>
</html>
