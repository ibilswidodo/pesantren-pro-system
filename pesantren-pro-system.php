<?php
/**
 * Plugin Name: Pesantren Pro-System
 * Description: Sistem Manajemen Pesantren Modern (Data Santri, Keuangan, CSV Importer, & PPDB).
 * Version: 1.0.0
 * Author: Alifbata Digital
 * Text Domain: pesantren-pro
 */

if (!defined('ABSPATH')) exit;

class PesantrenProSystem {

    public function __construct() {
        // Core Hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('init', array($this, 'register_post_types'));
        add_action('admin_menu', array($this, 'create_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Features
        add_action('admin_init', array($this, 'handle_csv_import'));
        add_action('admin_init', array($this, 'handle_bulk_spp'));
        add_shortcode('ppdb_form', array($this, 'render_ppdb_form'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('init', array($this, 'handle_ppdb_submission'));
    }

    /**
     * 1. DATABASE INITIALIZATION
     */
    public function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pesantren_keuangan';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            santri_id bigint(20) NOT NULL,
            jenis varchar(50) NOT NULL,
            jumlah decimal(15,2) NOT NULL,
            status varchar(20) DEFAULT 'unpaid',
            tanggal datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * 2. CUSTOM POST TYPES
     */
    public function register_post_types() {
        $types = ['santri' => 'Santri', 'pengurus' => 'Pengurus', 'pendaftaran' => 'Pendaftaran'];
        foreach ($types as $slug => $label) {
            register_post_type($slug, array(
                'labels' => array('name' => $label, 'singular_name' => $label),
                'public' => true,
                'show_in_menu' => false,
                'supports' => array('title', 'editor', 'custom-fields'),
                'menu_icon' => 'dashicons-groups',
            ));
        }
    }

    /**
     * 3. UI/UX ASSETS (Tailwind & Inter Font)
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'pesantren-pro') === false) return;
        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        wp_enqueue_script('tailwind-cdn', 'https://cdn.tailwindcss.com');
        echo '<style>body{font-family:"Inter", sans-serif; background: #f0f2f5;} #wpcontent{padding-left: 0 !important;}</style>';
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_script('tailwind-cdn', 'https://cdn.tailwindcss.com');
    }

    /**
     * 4. ADMIN MENU & TABS
     */
    public function create_menu() {
        add_menu_page('Pesantren Pro', 'Pesantren Pro', 'manage_options', 'pesantren-pro', array($this, 'render_admin_page'), 'dashicons-school', 6);
    }

    public function render_admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        ?>
        <div class="bg-slate-50 min-h-screen">
            <nav class="bg-emerald-700 p-6 text-white shadow-lg">
                <div class="flex justify-between items-center">
                    <h1 class="text-2xl font-bold tracking-tight">Pesantren Pro <span class="text-emerald-200 text-sm font-normal">v1.0</span></h1>
                    <div class="flex space-x-4">
                        <?php $this->nav_item('dashboard', 'Dashboard', $tab); ?>
                        <?php $this->nav_item('santri', 'Data Santri', $tab); ?>
                        <?php $this->nav_item('import', 'Import CSV', $tab); ?>
                        <?php $this->nav_item('keuangan', 'Keuangan', $tab); ?>
                        <?php $this->nav_item('pengaturan', 'Pengaturan', $tab); ?>
                    </div>
                </div>
            </nav>

            <div class="p-8 max-w-7xl mx-auto">
                <?php
                switch ($tab) {
                    case 'dashboard': $this->view_dashboard(); break;
                    case 'santri': $this->view_santri(); break;
                    case 'import': $this->view_import(); break;
                    case 'keuangan': $this->view_keuangan(); break;
                    case 'pengaturan': $this->view_settings(); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function nav_item($slug, $label, $active) {
        $class = ($slug === $active) ? 'bg-emerald-800 text-white' : 'text-emerald-100 hover:text-white';
        echo "<a href='?page=pesantren-pro&tab=$slug' class='px-4 py-2 rounded-md transition-all font-medium $class'>$label</a>";
    }

    /**
     * 5. VIEWS & DASHBOARD
     */
    private function view_dashboard() {
        global $wpdb;
        $count_santri = wp_count_posts('santri')->publish;
        $total_uang = $wpdb->get_var("SELECT SUM(jumlah) FROM {$wpdb->prefix}pesantren_keuangan WHERE status='paid'");
        $total_piutang = $wpdb->get_var("SELECT SUM(jumlah) FROM {$wpdb->prefix}pesantren_keuangan WHERE status='unpaid'");
        ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-emerald-500">
                <p class="text-sm text-gray-500 font-semibold uppercase">Total Santri</p>
                <h2 class="text-3xl font-bold text-gray-800"><?php echo esc_html($count_santri); ?></h2>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-blue-500">
                <p class="text-sm text-gray-500 font-semibold uppercase">Total Saldo Masuk</p>
                <h2 class="text-3xl font-bold text-emerald-600">Rp <?php echo number_format($total_uang ?: 0, 0, ',', '.'); ?></h2>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-red-500">
                <p class="text-sm text-gray-500 font-semibold uppercase">Total Tunggakan</p>
                <h2 class="text-3xl font-bold text-red-600">Rp <?php echo number_format($total_piutang ?: 0, 0, ',', '.'); ?></h2>
            </div>
        </div>
        <?php
    }

    private function view_santri() {
        $santris = get_posts(['post_type' => 'santri', 'numberposts' => -1]);
        ?>
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-left">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="p-4 font-semibold text-gray-600">NIS</th>
                        <th class="p-4 font-semibold text-gray-600">Nama Santri</th>
                        <th class="p-4 font-semibold text-gray-600">Kamar / Kelas</th>
                        <th class="p-4 font-semibold text-gray-600">WA Wali</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach($santris as $s): ?>
                    <tr>
                        <td class="p-4 font-mono text-emerald-700"><?php echo get_post_meta($s->ID, 'nis', true); ?></td>
                        <td class="p-4 font-bold"><?php echo $s->post_title; ?></td>
                        <td class="p-4 text-gray-500"><?php echo get_post_meta($s->ID, 'kamar', true) . ' / ' . get_post_meta($s->ID, 'kelas', true); ?></td>
                        <td class="p-4"><?php echo get_post_meta($s->ID, 'wa_wali', true); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 6. CSV IMPORTER LOGIC
     */
    private function view_import() {
        ?>
        <div class="max-w-2xl bg-white p-8 rounded-xl shadow-sm">
            <h3 class="text-xl font-bold mb-4">Import Data Santri (CSV)</h3>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <?php wp_nonce_field('csv_import_nonce', 'csv_nonce'); ?>
                <div class="border-2 border-dashed border-gray-300 p-8 text-center rounded-lg">
                    <input type="file" name="csv_file" accept=".csv" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer" required />
                </div>
                <div class="flex gap-4">
                    <button type="submit" name="import_csv" class="bg-emerald-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-emerald-700">Mulai Import</button>
                    <a href="<?php echo admin_url('admin-ajax.php?action=download_csv_template'); ?>" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg font-bold hover:bg-gray-200">Download Template</a>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_csv_import() {
        if (!isset($_POST['import_csv']) || !current_user_can('manage_options')) return;
        check_admin_referer('csv_import_nonce', 'csv_nonce');

        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle); // Skip header
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $nis = sanitize_text_field($data[0]);
                
                // Cek Duplikasi NIS
                $exists = get_posts(['post_type' => 'santri', 'meta_key' => 'nis', 'meta_value' => $nis]);
                if ($exists) continue;

                $post_id = wp_insert_post([
                    'post_title' => sanitize_text_field($data[1]),
                    'post_type' => 'santri',
                    'post_status' => 'publish'
                ]);

                update_post_meta($post_id, 'nis', $nis);
                update_post_meta($post_id, 'wa_wali', sanitize_text_field($data[2]));
                update_post_meta($post_id, 'kamar', sanitize_text_field($data[3]));
                update_post_meta($post_id, 'kelas', sanitize_text_field($data[4]));
            }
            fclose($handle);
            add_action('admin_notices', function(){ echo '<div class="updated"><p>Data berhasil diimport!</p></div>'; });
        }
    }

    /**
     * 7. KEUANGAN & BULK BILLING
     */
    private function view_keuangan() {
        global $wpdb;
        $table = $wpdb->prefix . 'pesantren_keuangan';
        $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY tanggal DESC LIMIT 20");
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-white p-8 rounded-xl shadow-sm">
                <h3 class="text-xl font-bold mb-4">Generate SPP Bulanan</h3>
                <p class="text-gray-500 mb-6 text-sm">Fitur ini akan membuat tagihan 'Unpaid' untuk seluruh santri yang aktif.</p>
                <form method="post">
                    <?php wp_nonce_field('bulk_spp_nonce', 'spp_nonce'); ?>
                    <div class="mb-4">
                        <label class="block text-sm font-bold mb-2">Jumlah Tagihan (IDR)</label>
                        <input type="number" name="nominal_spp" class="w-full p-2 border rounded" placeholder="500000" required>
                    </div>
                    <button type="submit" name="generate_spp" class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold">Generate Massal</button>
                </form>
            </div>

            <div class="bg-white p-8 rounded-xl shadow-sm">
                <h3 class="text-xl font-bold mb-4">Log Transaksi Terakhir</h3>
                <div class="space-y-3">
                    <?php foreach($logs as $log): 
                        $santri_name = get_the_title($log->santri_id);
                        $status_color = ($log->status == 'paid') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                    ?>
                    <div class="flex justify-between items-center p-3 border rounded-lg">
                        <div>
                            <p class="font-bold text-sm"><?php echo $santri_name; ?></p>
                            <p class="text-xs text-gray-400"><?php echo $log->tanggal; ?></p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold">Rp <?php echo number_format($log->jumlah, 0, ',', '.'); ?></p>
                            <span class="text-[10px] px-2 py-1 rounded uppercase font-bold <?php echo $status_color; ?>"><?php echo $log->status; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_bulk_spp() {
        if (!isset($_POST['generate_spp']) || !current_user_can('manage_options')) return;
        check_admin_referer('bulk_spp_nonce', 'spp_nonce');

        global $wpdb;
        $nominal = absint($_POST['nominal_spp']);
        $santris = get_posts(['post_type' => 'santri', 'numberposts' => -1]);

        foreach ($santris as $s) {
            $wpdb->insert($wpdb->prefix . 'pesantren_keuangan', [
                'santri_id' => $s->ID,
                'jenis' => 'SPP Bulanan',
                'jumlah' => $nominal,
                'status' => 'unpaid',
                'tanggal' => current_time('mysql')
            ]);
        }
        add_action('admin_notices', function(){ echo '<div class="updated"><p>Tagihan massal berhasil dibuat!</p></div>'; });
    }

    /**
     * 8. SETTINGS (WA API)
     */
    private function view_settings() {
        ?>
        <div class="max-w-xl bg-white p-8 rounded-xl shadow-sm">
            <h3 class="text-xl font-bold mb-6">Integrasi WhatsApp</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">API Provider</label>
                    <select class="w-full p-2 border rounded-md">
                        <option>Fonnte</option>
                        <option>Wablas</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">API Key / Token</label>
                    <input type="password" class="w-full p-2 border rounded-md" placeholder="********************">
                </div>
                <button class="bg-gray-800 text-white px-6 py-2 rounded-lg opacity-50 cursor-not-allowed">Save Configuration (Pro Only)</button>
            </div>
        </div>
        <?php
    }

    /**
     * 9. FRONT-END PPDB FORM (Shortcode)
     */
    public function render_ppdb_form() {
        ob_start();
        ?>
        <div class="max-w-md mx-auto bg-white p-8 rounded-2xl shadow-xl border border-gray-100 my-10">
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-emerald-800">Pendaftaran Santri Baru</h2>
                <p class="text-gray-500 text-sm">Lengkapi formulir di bawah ini</p>
            </div>
            <form action="" method="POST" class="space-y-5">
                <?php wp_nonce_field('ppdb_action', 'ppdb_nonce'); ?>
                <div class="relative">
                    <input type="text" name="nama_lengkap" class="peer w-full border-b-2 border-gray-300 focus:border-emerald-600 outline-none p-2 placeholder-transparent" placeholder="Nama Lengkap" required>
                    <label class="absolute left-2 -top-3.5 text-gray-600 text-xs transition-all peer-placeholder-shown:text-base peer-placeholder-shown:text-gray-400 peer-placeholder-shown:top-2 peer-focus:-top-3.5 peer-focus:text-emerald-600 peer-focus:text-xs">Nama Lengkap</label>
                </div>
                <div class="relative">
                    <input type="text" name="wa_wali" class="peer w-full border-b-2 border-gray-300 focus:border-emerald-600 outline-none p-2 placeholder-transparent" placeholder="WhatsApp Wali" required>
                    <label class="absolute left-2 -top-3.5 text-gray-600 text-xs transition-all peer-placeholder-shown:text-base peer-placeholder-shown:text-gray-400 peer-placeholder-shown:top-2 peer-focus:-top-3.5 peer-focus:text-emerald-600 peer-focus:text-xs">WhatsApp Wali</label>
                </div>
                <div class="relative">
                    <textarea name="alamat" class="peer w-full border-b-2 border-gray-300 focus:border-emerald-600 outline-none p-2 placeholder-transparent" placeholder="Alamat"></textarea>
                    <label class="absolute left-2 -top-3.5 text-gray-600 text-xs transition-all peer-placeholder-shown:text-base peer-placeholder-shown:text-gray-400 peer-placeholder-shown:top-2 peer-focus:-top-3.5 peer-focus:text-emerald-600 peer-focus:text-xs">Alamat Domisili</label>
                </div>
                <button type="submit" name="submit_ppdb" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl transition-all shadow-lg">Kirim Pendaftaran</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_ppdb_submission() {
        if (!isset($_POST['submit_ppdb'])) return;
        check_admin_referer('ppdb_action', 'ppdb_nonce');

        $nama = sanitize_text_field($_POST['nama_lengkap']);
        $wa = sanitize_text_field($_POST['wa_wali']);
        $alamat = sanitize_textarea_field($_POST['alamat']);

        $post_id = wp_insert_post([
            'post_title' => $nama,
            'post_type' => 'pendaftaran',
            'post_status' => 'publish',
            'post_content' => $alamat
        ]);

        update_post_meta($post_id, 'wa_wali', $wa);

        wp_redirect(add_query_arg('status', 'success', home_url($GLOBALS['wp']->request)));
        exit;
    }
}

// Helper AJAX: Template CSV
add_action('wp_ajax_download_csv_template', function(){
    if (!current_user_can('manage_options')) return;
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="template_santri.csv"');
    echo "nis,nama_santri,wa_wali,kamar,kelas\n";
    echo "12345,Ahmad Zaki,08123456789,Al-Barokah 01,10-A\n";
    exit;
});

new PesantrenProSystem();
