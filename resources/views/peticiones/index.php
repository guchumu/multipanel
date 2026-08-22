<?php ob_start(); ?>
<?php
/** @var array $counts */
/** @var array $items */
/** @var array $motivos */
/** @var array $platformsById */
/** @var list<int> $needsTmdbIds */
/** @var string $filter */
/** @var string|null $error */
/** @var bool $configured */
/** @var int $page */
/** @var bool $hasTmdb */

$tabClass = static function (string $name, string $current): string {
    return 'nav-link' . ($name === $current ? ' active' : '');
};
$badge = static function (int $n): string {
    return $n > 0 ? ' <span class="badge rounded-pill bg-secondary">' . $n . '</span>' : '';
};
$needsTmdbIds = $needsTmdbIds ?? [];
$hasTmdb = $hasTmdb ?? false;
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0">Peticiones</h4>
        <div class="text-muted small">BD remota legacy (panel viejo en paralelo)</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/settings#peticiones" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-database me-1"></i>BD remota
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnComprobarServidor"
                <?= !$configured ? 'disabled' : '' ?>
                title="Si una denegada ya está en Plex o Jellyfin, se marca como subida y deja de aparecer aquí">
            <i class="bi bi-hdd-network me-1"></i>Comprobar en servidor
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="btnActualizarCaratulas"
                <?= !$configured || !$hasTmdb ? 'disabled' : '' ?>
                title="<?= $hasTmdb ? 'Busca carátulas y plataformas en TMDb (página actual)' : 'Configura la clave TMDb en Ajustes' ?>">
            <i class="bi bi-image me-1"></i>Actualizar carátulas
        </button>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddPeticion" <?= !$configured ? 'disabled' : '' ?>>
            <i class="bi bi-plus-lg me-1"></i>Añadir URL
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-warning border"><?= e($error) ?></div>
<?php endif; ?>

<ul class="nav nav-pills flex-wrap gap-1 mb-4">
    <li class="nav-item">
        <a class="<?= $tabClass('pendientes', $filter) ?>" href="/peticiones?filtro=pendientes">
            Pendientes<?= $badge((int) ($counts['pendientes'] ?? 0)) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="<?= $tabClass('proceso', $filter) ?>" href="/peticiones?filtro=proceso">
            En proceso<?= $badge((int) ($counts['proceso'] ?? 0)) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="<?= $tabClass('denegadas', $filter) ?>" href="/peticiones?filtro=denegadas">
            Denegadas<?= $badge((int) ($counts['denegadas'] ?? 0)) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="<?= $tabClass('todas', $filter) ?>" href="/peticiones?filtro=todas">
            Todas<?= $badge((int) ($counts['todas'] ?? 0)) ?>
        </a>
    </li>
</ul>

<?php if ($configured && empty($error) && !$hasTmdb): ?>
<div class="alert alert-light border small">Sin clave TMDb no se pueden cargar carátulas ni plataformas. Añádela en <a href="/settings#peticiones">Configuración → Peticiones</a>.</div>
<?php endif; ?>

<?php if ($configured && empty($error) && empty($items)): ?>
<div class="text-center text-muted py-5">No hay peticiones en esta pestaña.</div>
<?php endif; ?>

<div class="row g-3 peticiones-grid">
<?php foreach ($items as $row): ?>
    <?php
    $id = (int) ($row['id'] ?? 0);
    $isDenied = ((string) ($row['activo'] ?? '1') === '0') || ((int) ($row['idmotivo'] ?? 0) > 0);
    $isAccepted = ((string) ($row['aceptado'] ?? '0') === '1');
    $border = $isDenied ? 'border-danger' : ($isAccepted ? 'border-success' : 'border-warning');
    $img = trim((string) ($row['img'] ?? ''));
    if ($img === '') {
        $img = 'https://via.placeholder.com/300x450?text=Sin+poster';
    }
    $platforms = $platformsById[$id] ?? [];
    $needsTmdb = $hasTmdb && in_array($id, $needsTmdbIds ?? [], true);
    $requestCount = max(1, (int) ($row['request_count'] ?? 1));
    $requesters = is_array($row['requesters'] ?? null) ? $row['requesters'] : [];
    if ($requesters === []) {
        $fallbackName = trim((string) ($row['username'] ?? ''));
        if ($fallbackName === '') {
            $fallbackName = trim((string) ($row['idusuario'] ?? '')) ?: 'Sin nombre';
        }
        $requesters = [['name' => $fallbackName, 'fecha' => (string) ($row['fechapeticion'] ?? ''), 'count' => 1]];
    }
    $requesterLabels = [];
    $requesterTitle = [];
    foreach ($requesters as $who) {
        $name = trim((string) ($who['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $times = (int) ($who['count'] ?? 1);
        $label = $times > 1 ? $name . ' ×' . $times : $name;
        $requesterLabels[] = $label;
        $fecha = trim((string) ($who['fecha'] ?? ''));
        $requesterTitle[] = $fecha !== '' ? $label . ' (' . $fecha . ')' : $label;
    }
    $requesterText = $requesterLabels === [] ? '—' : implode(', ', $requesterLabels);
    ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2" id="peticion-card-<?= $id ?>"<?= $needsTmdb ? ' data-needs-tmdb="1"' : '' ?><?= $isDenied ? ' data-denied="1"' : '' ?> data-count="<?= $requestCount ?>">
        <div class="card h-100 shadow-sm peticion-card <?= e($border) ?> border-2">
            <a href="<?= e((string) ($row['url'] ?? '#')) ?>" target="_blank" rel="noopener" class="peticion-poster-link">
                <img src="<?= e($img) ?>" class="card-img-top peticion-poster" alt="<?= e((string) ($row['nombrepeticion'] ?? '')) ?>" loading="lazy"
                     onerror="this.src='https://via.placeholder.com/300x450?text=Sin+poster'">
                <?php if ($requestCount > 1): ?>
                <span class="peticion-count-badge" title="<?= $requestCount ?> solicitudes"><?= $requestCount ?></span>
                <?php endif; ?>
            </a>
            <div class="card-body p-2 d-flex flex-column gap-1">
                <div class="peticion-title form-control form-control-sm"
                     contenteditable="true"
                     data-id="<?= $id ?>"
                     title="Editar título (se guarda al salir)"><?= e((string) ($row['nombrepeticion'] ?? '')) ?></div>
                <div class="small peticion-requesters" title="<?= e(implode("\n", $requesterTitle)) ?>">
                    <i class="bi bi-people"></i>
                    <span class="peticion-requesters-count"><?= $requestCount === 1 ? '1 solicitud' : $requestCount . ' solicitudes' ?></span>
                    <div class="peticion-requesters-names text-muted"><?= e($requesterText) ?></div>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-calendar3"></i> <?= e((string) ($row['fechapeticion'] ?? '—')) ?>
                </div>
                <?php if ($hasTmdb): ?>
                <div class="small peticion-streaming">
                    <?php if ($platforms === []): ?>
                        <span class="text-muted">Ver: —</span>
                    <?php else: ?>
                        <span class="text-muted">Disponible en:</span>
                        <span class="peticion-streaming-logos">
                        <?php foreach ($platforms as $p): ?>
                            <?php
                            $pName = is_array($p) ? (string) ($p['nombre'] ?? '') : (string) $p;
                            $pLogo = is_array($p) ? (string) ($p['logo'] ?? '') : '';
                            ?>
                            <?php if ($pLogo !== ''): ?>
                                <img class="peticion-provider-logo" src="<?= e($pLogo) ?>" alt="<?= e($pName) ?>" title="<?= e($pName) ?>" width="32" height="32">
                            <?php elseif ($pName !== ''): ?>
                                <span class="badge text-bg-light border"><?= e($pName) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="mt-auto d-flex flex-wrap gap-1 pt-1 peticion-actions">
                    <?php if (!$isDenied && !$isAccepted): ?>
                    <button type="button" class="btn btn-success btn-sm flex-fill peticion-action-btn" data-action="aceptar" data-id="<?= $id ?>" data-count="<?= $requestCount ?>" title="Aceptar">
                        <i class="bi bi-check-lg"></i><span class="d-none d-sm-inline ms-1">Aceptar</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($isAccepted && !$isDenied): ?>
                    <button type="button" class="btn btn-primary btn-sm flex-fill peticion-action-btn" data-action="subir" data-id="<?= $id ?>" data-count="<?= $requestCount ?>" title="Marcar subida">
                        <i class="bi bi-cloud-upload"></i><span class="d-none d-sm-inline ms-1">Subir</span>
                    </button>
                    <?php endif; ?>
                    <?php if (!$isDenied): ?>
                    <button type="button" class="btn btn-warning btn-sm flex-fill peticion-action-btn" data-action="denegar-open" data-id="<?= $id ?>" data-count="<?= $requestCount ?>"
                            data-title="<?= e((string) ($row['nombrepeticion'] ?? '')) ?>" title="Denegar">
                        <i class="bi bi-x-lg"></i><span class="d-none d-sm-inline ms-1">Denegar</span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-danger btn-sm flex-fill peticion-action-btn" data-action="borrar" data-id="<?= $id ?>" data-count="<?= $requestCount ?>" title="Borrar">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php
$totalForFilter = (int) ($counts[$filter] ?? 0);
$hasMore = ($page * $perPage) < $totalForFilter;
if ($hasMore):
?>
<div class="text-center mt-4">
    <a class="btn btn-outline-secondary" href="/peticiones?filtro=<?= e($filter) ?>&page=<?= $page + 1 ?>">Cargar más</a>
</div>
<?php endif; ?>

<!-- Modal denegar -->
<div class="modal fade" id="modalDenegar" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="formDenegar">
            <div class="modal-header">
                <h5 class="modal-title">Denegar petición</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="modal_id_peli" value="">
                <p class="small text-muted mb-2" id="modal_titulo_peli"></p>
                <p class="small text-muted mb-2 d-none" id="modal_grupo_peli"></p>
                <label class="form-label">Motivo</label>
                <select name="id_motivo" id="motivo_denegacion" class="form-select" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($motivos as $m): ?>
                    <option value="<?= (int) $m['id'] ?>"><?= e((string) ($m['nombre'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning">Denegar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal añadir -->
<div class="modal fade" id="modalAddPeticion" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="formAddPeticion">
            <div class="modal-header">
                <h5 class="modal-title">Añadir petición (manual)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">URL</label>
                    <input type="url" name="url" class="form-control" required placeholder="https://www.imdb.com/title/tt… o Filmaffinity">
                </div>
                <div class="mb-3">
                    <label class="form-label">Título</label>
                    <input type="text" name="titulo" class="form-control" placeholder="Opcional si pegas IMDb o Filmaffinity">
                </div>
                <div class="mb-3">
                    <label class="form-label">Imagen (URL poster)</label>
                    <input type="url" name="img" class="form-control" placeholder="Opcional">
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Telegram chat id</label>
                        <input type="text" name="idusuario" class="form-control" placeholder="Opcional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Usuario</label>
                        <input type="text" name="username" class="form-control" placeholder="Opcional">
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0">Si pegas IMDb o Filmaffinity, se toma la carátula (y el título si lo dejas vacío).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div id="peticionesToast" class="position-fixed bottom-0 end-0 p-3" style="z-index:1080"></div>

<?php
$scripts = <<<'JS'
<script>
(function () {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

  function toast(msg, ok) {
    const wrap = document.getElementById('peticionesToast');
    if (!wrap) return;
    const el = document.createElement('div');
    el.className = 'alert alert-' + (ok ? 'success' : 'danger') + ' shadow-sm py-2 px-3 mb-2';
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3200);
  }

  async function postAction(body) {
    const res = await fetch('/peticiones/action', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });
    let data = {};
    try { data = await res.json(); } catch (e) { data = { ok: false, message: 'Respuesta inválida' }; }
    return { res, data };
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function renderPlatforms(list) {
    if (!Array.isArray(list) || list.length === 0) {
      return '<span class="text-muted">No se encontró en ninguna plataforma de suscripción en España.</span>';
    }
    const logos = list.map((p) => {
      const name = escapeHtml(p.nombre || p || '');
      const logo = p.logo || '';
      if (logo) {
        return '<img class="peticion-provider-logo" src="' + escapeHtml(logo) + '" alt="' + name + '" title="' + name + '" width="32" height="32">';
      }
      return name ? '<span class="badge text-bg-light border">' + name + '</span>' : '';
    }).join('');
    return '<span class="text-muted">Disponible en:</span> <span class="peticion-streaming-logos">' + logos + '</span>';
  }

  function applyMeta(id, data) {
    const card = document.getElementById('peticion-card-' + id);
    if (!card) return;
    if (data.poster) {
      const img = card.querySelector('.peticion-poster');
      if (img) img.src = data.poster;
    }
    if (data.titulo) {
      const titleEl = card.querySelector('.peticion-title');
      const current = (titleEl?.textContent || '').trim();
      if (titleEl && (!current || /^tt\d{7,}$/i.test(current) || /^film\d+$/i.test(current))) {
        titleEl.textContent = data.titulo;
      }
    }
    const wrap = card.querySelector('.peticion-streaming');
    if (wrap && data.plataformas) {
      wrap.innerHTML = renderPlatforms(data.plataformas);
    }
    card.removeAttribute('data-needs-tmdb');
  }

  async function loadMetaQueue(ids) {
    const queue = ids.slice();
    const workers = 3;
    async function worker() {
      while (queue.length) {
        const id = queue.shift();
        if (!id) continue;
        const { data } = await postAction({ accion: 'meta', id });
        if (data.ok) applyMeta(id, data);
      }
    }
    await Promise.all(Array.from({ length: workers }, () => worker()));
  }

  document.querySelectorAll('[data-action]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const action = btn.getAttribute('data-action');
      const id = parseInt(btn.getAttribute('data-id') || '0', 10);
      const count = parseInt(btn.getAttribute('data-count') || '1', 10);
      if (!id) return;

      if (action === 'denegar-open') {
        document.getElementById('modal_id_peli').value = String(id);
        document.getElementById('modal_titulo_peli').textContent = btn.getAttribute('data-title') || '';
        const groupHint = document.getElementById('modal_grupo_peli');
        if (groupHint) {
          if (count > 1) {
            groupHint.textContent = 'Se denegará a las ' + count + ' solicitudes y se avisará a todos.';
            groupHint.classList.remove('d-none');
          } else {
            groupHint.textContent = '';
            groupHint.classList.add('d-none');
          }
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDenegar')).show();
        return;
      }

      if (action === 'borrar' && !confirm(count > 1
        ? '¿Borrar esta película y las ' + count + ' solicitudes?'
        : '¿Borrar esta petición?')) return;

      btn.disabled = true;
      const { data } = await postAction({ accion: action, id });
      btn.disabled = false;
      toast(data.message || (data.ok ? 'OK' : 'Error'), !!data.ok);
      if (data.ok) {
        const card = document.getElementById('peticion-card-' + id);
        if (card) card.remove();
        else location.reload();
      }
    });
  });

  document.querySelectorAll('.peticion-title').forEach((el) => {
    el.addEventListener('blur', async () => {
      const id = parseInt(el.getAttribute('data-id') || '0', 10);
      const titulo = (el.textContent || '').trim();
      if (!id || !titulo) return;
      const { data } = await postAction({ accion: 'rename', id, titulo });
      if (!data.ok) toast(data.message || 'No se pudo guardar el título', false);
    });
  });

  const formDenegar = document.getElementById('formDenegar');
  formDenegar?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = parseInt(document.getElementById('modal_id_peli').value || '0', 10);
    const id_motivo = parseInt(document.getElementById('motivo_denegacion').value || '0', 10);
    const { data } = await postAction({ accion: 'denegar', id, id_motivo });
    toast(data.message || (data.ok ? 'OK' : 'Error'), !!data.ok);
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('modalDenegar'))?.hide();
      document.getElementById('peticion-card-' + id)?.remove();
    }
  });

  const formAdd = document.getElementById('formAddPeticion');
  formAdd?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(formAdd);
    const body = Object.fromEntries(fd.entries());
    body.accion = 'add';
    const { data } = await postAction(body);
    toast(data.message || (data.ok ? 'OK' : 'Error'), !!data.ok);
    if (data.ok) location.reload();
  });

  const pendingIds = Array.from(document.querySelectorAll('[data-needs-tmdb="1"]'))
    .map((el) => parseInt((el.id || '').replace('peticion-card-', ''), 10))
    .filter((id) => id > 0);
  if (pendingIds.length) {
    loadMetaQueue(pendingIds);
  }

  document.getElementById('btnActualizarCaratulas')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    const ids = Array.from(document.querySelectorAll('[id^="peticion-card-"]'))
      .map((el) => parseInt((el.id || '').replace('peticion-card-', ''), 10))
      .filter((id) => id > 0);
    if (!ids.length) return;
    btn.disabled = true;
    const { data } = await postAction({ accion: 'actualizar-metadatos', ids });
    toast(data.message || (data.ok ? 'OK' : 'Error'), !!data.ok);
    btn.disabled = false;
    if (data.ok) location.reload();
  });

  async function checkOnServer(ids) {
    if (!ids.length) return { data: { ok: true, updated: 0, message: 'Nada que comprobar' } };
    return postAction({ accion: 'comprobar-servidor', ids });
  }

  const deniedIds = Array.from(document.querySelectorAll('[data-denied="1"]'))
    .map((el) => parseInt((el.id || '').replace('peticion-card-', ''), 10))
    .filter((id) => id > 0);
  if (deniedIds.length) {
    (async () => {
      const queue = deniedIds.slice();
      const workers = 2;
      let found = 0;
      async function worker() {
        while (queue.length) {
          const id = queue.shift();
          if (!id) continue;
          const { data } = await postAction({ accion: 'comprobar-servidor', id });
          if (data.ok && data.found) {
            found++;
            document.getElementById('peticion-card-' + id)?.remove();
          }
        }
      }
      await Promise.all(Array.from({ length: workers }, () => worker()));
      if (found > 0) toast('En catálogo (ya no denegadas): ' + found, true);
    })();
  }

  document.getElementById('btnComprobarServidor')?.addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    const ids = Array.from(document.querySelectorAll('[data-denied="1"]'))
      .map((el) => parseInt((el.id || '').replace('peticion-card-', ''), 10))
      .filter((id) => id > 0);
    if (!ids.length) {
      toast('No hay denegadas visibles para comprobar', false);
      return;
    }
    btn.disabled = true;
    const { data } = await checkOnServer(ids);
    toast(data.message || (data.ok ? 'OK' : 'Error'), !!data.ok);
    btn.disabled = false;
    if (data.ok && (data.updated || 0) > 0) location.reload();
  });
})();
</script>
JS;
?>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
