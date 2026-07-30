<?php ob_start(); ?>
<h4 class="mb-4">Importar / Exportar usuarios</h4>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Importar usuarios media</h6>
                <p class="text-muted small">Formatos: CSV, JSON. Columnas CSV: username, email, password, status, max_streams, expires_at...</p>
                <form method="POST" action="/import" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <select name="type" class="form-select form-select-sm">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="mb-3"><input type="file" name="file" class="form-control" accept=".csv,.json,.txt" required></div>
                    <button class="btn btn-primary">Importar</button>
                    <a href="/import/template" class="btn btn-outline-secondary ms-2">Descargar plantilla CSV</a>
                </form>
                <?php use Core\Session; if ($errs = Session::getInstance()->getFlash('import_errors')): ?>
                <pre class="mt-3 small text-danger bg-light p-2 rounded"><?= e($errs) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6>Exportar</h6>
                <ul class="list-unstyled">
                    <li class="mb-2"><a href="/logs/export" class="btn btn-outline-secondary btn-sm w-100">Logs auditoría (CSV)</a></li>
                    <li class="mb-2"><a href="/stats/export" class="btn btn-outline-secondary btn-sm w-100">Estadísticas streaming (CSV)</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
