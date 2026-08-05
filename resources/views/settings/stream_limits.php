<?php
/** @var array{enforcement_enabled: bool, default_max_streams: int, kill_message: string, count_mode: string} $settings */
/** @var string $effectiveKillMessage */
ob_start();
?>
<div class="mb-4">
    <a href="/settings" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Configuración</a>
    <h4 class="mb-0 mt-1">Límite de streams simultáneos</h4>
    <p class="text-muted small mb-0">
        Por defecto cuenta <strong>IPs distintas</strong> (varias pantallas en casa = 1).
        Si se supera el límite, se cortan las sesiones de las IPs más recientes.
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings/stream-limits">
                    <?= csrf_field() ?>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="enforcement_enabled" name="enforcement_enabled" value="1"
                               <?= !empty($settings['enforcement_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enforcement_enabled">
                            <strong>Aplicar límite automáticamente</strong>
                            <div class="small text-muted">Si está desactivado solo se registra/visualiza el exceso en En directo (badge), sin cortar.</div>
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="default_max_streams">Límite por defecto del tenant</label>
                        <input type="number" min="1" max="50" class="form-control" style="max-width: 140px"
                               id="default_max_streams" name="default_max_streams"
                               value="<?= (int) ($settings['default_max_streams'] ?? 2) ?>">
                        <div class="form-text">
                            Valor por defecto: <strong>2</strong>. Se usa cuando el usuario no tiene «Streams» propio (vacío / NULL).
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="count_mode">Cómo contar</label>
                        <select class="form-select" id="count_mode" name="count_mode" style="max-width: 360px">
                            <option value="distinct_ip" <?= ($settings['count_mode'] ?? 'distinct_ip') === 'distinct_ip' ? 'selected' : '' ?>>
                                IPs distintas (recomendado)
                            </option>
                            <option value="sessions" <?= ($settings['count_mode'] ?? '') === 'sessions' ? 'selected' : '' ?>>
                                Cada sesión / emisión
                            </option>
                        </select>
                        <div class="form-text">
                            Con «IPs distintas»: 3 sesiones en la misma IP cuentan como 1 hacia el límite.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kill_message">Mensaje al cortar (opcional)</label>
                        <textarea class="form-control" id="kill_message" name="kill_message" rows="3"
                                  maxlength="500"
                                  placeholder="Vacío = mensaje predeterminado de «Mensajes al detener»"><?= e($settings['kill_message'] ?? '') ?></textarea>
                        <div class="form-text">
                            Mensaje efectivo actual:
                            <em><?= e($effectiveKillMessage ?? '') ?></em>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Guardar
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2">Cómo funciona</h6>
                <ul class="small text-muted mb-3 ps-3">
                    <li>Match por usuario media en su servidor (Plex/Jellyfin user id, o nombre).</li>
                    <li>Modo IPs: se conservan las IPs más antiguas; se cortan todas las sesiones de las IPs nuevas de más.</li>
                    <li>Modo sesiones: se conserva la emisión con más progreso.</li>
                    <li>Se aplica al refrescar En directo y en el cron <code>streams</code> (o <code>all</code>).</li>
                </ul>
                <a href="/media-users/stream-violations" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-exclamation-octagon me-1"></i>Ver incumplimientos
                </a>
                <a href="/settings/stop-messages" class="btn btn-outline-secondary btn-sm ms-1">
                    Mensajes al detener
                </a>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
