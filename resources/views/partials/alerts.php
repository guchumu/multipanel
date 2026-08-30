<?php
use Core\Session;
$error = Session::getInstance()->getFlash('error');
$success = Session::getInstance()->getFlash('success');
$warning = Session::getInstance()->getFlash('warning');
?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($warning): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert" style="white-space: pre-line;">
    <?= e($warning) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert" style="white-space: pre-line;">
    <?= e($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
