<?php ob_start(); ?>
<h4 class="mb-4">Permisos: <?= e($role['name']) ?></h4>
<form method="POST" action="/roles/<?= (int) $role['id'] ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="PUT">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php
            $grouped = [];
            foreach ($permissions as $p) {
                $grouped[$p['group'] ?? 'general'][] = $p;
            }
            foreach ($grouped as $group => $perms): ?>
            <h6 class="text-muted mt-3"><?= e(ucfirst($group)) ?></h6>
            <?php foreach ($perms as $p): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>"
                    id="perm-<?= (int) $p['id'] ?>" <?= in_array($p['id'], $assigned, false) ? 'checked' : '' ?>>
                <label class="form-check-label" for="perm-<?= (int) $p['id'] ?>"><?= e($p['name']) ?> <code class="small"><?= e($p['slug']) ?></code></label>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="btn btn-primary mt-3"><?= __('save') ?></button>
    <a href="/roles" class="btn btn-link"><?= __('cancel') ?></a>
</form>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
