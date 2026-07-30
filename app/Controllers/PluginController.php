<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Plugins\PluginManager;
use App\Services\AuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Plugin management controller.
 */
class PluginController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $installed = [];
        try {
            $installed = Database::getInstance()->fetchAll('SELECT * FROM plugins ORDER BY name');
        } catch (\Throwable) {
            // table may not exist yet
        }

        $discovered = PluginManager::discover();
        $installedSlugs = array_column($installed, 'slug');

        return $this->view('plugins.index', [
            'title' => 'Plugins',
            'installed' => $installed,
            'discovered' => $discovered,
            'installedSlugs' => $installedSlugs,
        ]);
    }

    public function install(Request $request, string $slug): Response
    {
        $meta = null;
        foreach (PluginManager::discover() as $p) {
            if (($p['slug'] ?? '') === $slug) {
                $meta = $p;
                break;
            }
        }

        if (!$meta) {
            Session::getInstance()->flash('error', 'Plugin no encontrado.');
            return $this->redirect('/plugins');
        }

        $existing = Database::getInstance()->fetchOne('SELECT id FROM plugins WHERE slug = ?', [$slug]);
        if (!$existing) {
            Database::getInstance()->insert('plugins', [
                'name' => $meta['name'],
                'slug' => $slug,
                'version' => $meta['version'],
                'description' => $meta['description'] ?? '',
                'author' => $meta['author'] ?? '',
                'is_active' => 0,
            ]);
        }

        PluginManager::activate($slug);
        Session::getInstance()->flash('success', "Plugin '{$meta['name']}' activado.");
        return $this->redirect('/plugins');
    }

    public function deactivate(Request $request, string $slug): Response
    {
        PluginManager::deactivate($slug);
        Session::getInstance()->flash('success', 'Plugin desactivado.');
        return $this->redirect('/plugins');
    }
}
