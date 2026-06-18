<?php
/**
 * Immutable HR Rulebook & Legal Evidence System
 * Single-file PHP/JS SPA (GitOps Architecture)
 */

define('CONFIG_FILE', __DIR__ . '/config.json');

// Helper function to send JSON API response
function send_json($data, $status = 200) {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Load current configuration if exists (supports server environment variables or config.json)
$config = [];
if (getenv('OPMS_GITHUB_TOKEN') && getenv('OPMS_REPO_OWNER') && getenv('OPMS_REPO_NAME')) {
    $config = [
        'github_token' => getenv('OPMS_GITHUB_TOKEN'),
        'repo_owner' => getenv('OPMS_REPO_OWNER'),
        'repo_name' => getenv('OPMS_REPO_NAME'),
        'company_name' => getenv('OPMS_COMPANY_NAME') ?: 'Office Policy System',
        'branch' => getenv('OPMS_BRANCH') ?: 'main',
        'admin_passcode' => getenv('OPMS_ADMIN_PASSCODE') ?: 'admin'
    ];
    $is_configured = true;
} else {
    $is_configured = file_exists(CONFIG_FILE);
    if ($is_configured) {
        $config = json_decode(file_get_contents(CONFIG_FILE), true);
    }
}

// Backend API Routing
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Setup Gatekeeper API
    if ($action === 'save_setup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json(['error' => 'Method not allowed'], 405);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $token = $data['github_token'] ?? '';
        $owner = $data['repo_owner'] ?? '';
        $repo = $data['repo_name'] ?? '';
        $company = $data['company_name'] ?? '';
        $branch = $data['branch'] ?? 'main';
        $admin_passcode = $data['admin_passcode'] ?? 'admin';

        if (!$token || !$owner || !$repo || !$company) {
            send_json(['error' => 'All fields (PAT, Owner, Repo, Company) are required.'], 400);
        }

        // Test credentials by making a request to the GitHub Repository API
        $url = "https://api.github.com/repos/{$owner}/{$repo}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For compatibility across varying local server environments
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "User-Agent: Immutable-HR-App",
            "Accept: application/vnd.github.v3+json"
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $err_msg = 'Verification failed. Please check your token, repository owner, and repository name.';
            $resp_data = json_decode($response, true);
            if (isset($resp_data['message'])) {
                $err_msg .= ' (GitHub Error: ' . $resp_data['message'] . ')';
            }
            send_json(['error' => $err_msg], 400);
        }

        // Check if rules.json exists, if not, create it with empty list to initialize
        $url_rules = "https://api.github.com/repos/{$owner}/{$repo}/contents/rules.json?ref={$branch}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_rules);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "User-Agent: Immutable-HR-App",
            "Accept: application/vnd.github.v3+json"
        ]);
        $response_rules = curl_exec($ch);
        $rules_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rules_code === 404) {
            // Initialize empty rules.json
            $init_body = [
                'message' => 'HR System Initialization: Created rules.json database',
                'content' => base64_encode(json_encode([], JSON_PRETTY_PRINT)),
                'branch' => $branch
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/{$owner}/{$repo}/contents/rules.json");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($init_body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}",
                "User-Agent: Immutable-HR-App",
                "Content-Type: application/json",
                "Accept: application/vnd.github.v3+json"
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // Save Configuration locally
        $config_data = [
            'github_token' => $token,
            'repo_owner' => $owner,
            'repo_name' => $repo,
            'company_name' => $company,
            'branch' => $branch,
            'admin_passcode' => $admin_passcode
        ];
        if (file_put_contents(CONFIG_FILE, json_encode($config_data, JSON_PRETTY_PRINT))) {
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'Unable to write config.json. Please verify directory permissions.'], 500);
        }
    }

    // Ensure system is configured for subsequent operations
    if (!$is_configured) {
        send_json(['error' => 'System has not been configured.'], 403);
    }

    // Verify Admin Passcode API
    if ($action === 'verify_admin') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json(['error' => 'Method not allowed'], 405);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $passcode = $data['passcode'] ?? '';
        $correct_passcode = $config['admin_passcode'] ?? 'admin';
        if ($passcode === $correct_passcode) {
            send_json(['success' => true]);
        } else {
            send_json(['error' => 'Invalid admin passcode.'], 401);
        }
    }

    $token = $config['github_token'];
    $owner = $config['repo_owner'];
    $repo = $config['repo_name'];
    $branch = $config['branch'] ?? 'main';

    // Helper function to query GitHub REST API
    function call_github_api($method, $endpoint, $payload = null) {
        global $token, $owner, $repo;
        $url = "https://api.github.com/repos/{$owner}/{$repo}" . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = [
            "Authorization: Bearer {$token}",
            "User-Agent: Immutable-HR-App",
            "Accept: application/vnd.github.v3+json"
        ];

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $headers[] = "Content-Type: application/json";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status_code,
            'body' => json_decode($response, true)
        ];
    }

    // Get active rules
    if ($action === 'get_rules') {
        $result = call_github_api('GET', "/contents/rules.json?ref={$branch}");
        if ($result['status'] === 404) {
            send_json(['rules' => [], 'sha' => null]);
        } elseif ($result['status'] === 200) {
            $raw_content = base64_decode($result['body']['content']);
            $rules_array = json_decode($raw_content, true) ?: [];
            send_json(['rules' => $rules_array, 'sha' => $result['body']['sha']]);
        } else {
            send_json(['error' => 'Unable to read policy rules from GitHub repository.', 'details' => $result['body']], $result['status']);
        }
    }

    // Create or edit a rule
    if ($action === 'save_rule') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json(['error' => 'Method not allowed'], 405);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $rule_id = $data['id'] ?? null;
        $title = $data['title'] ?? '';
        $description = $data['description'] ?? '';
        $category = $data['category'] ?? 'General';

        if (!$title || !$description) {
            send_json(['error' => 'Title and Description are mandatory fields.'], 400);
        }

        // Fetch current rules.json state from GitHub
        $result = call_github_api('GET', "/contents/rules.json?ref={$branch}");
        $rules = [];
        $sha = null;

        if ($result['status'] === 200) {
            $raw_content = base64_decode($result['body']['content']);
            $rules = json_decode($raw_content, true) ?: [];
            $sha = $result['body']['sha'];
        } elseif ($result['status'] !== 404) {
            send_json(['error' => 'Unable to fetch current repository policies.', 'details' => $result['body']], $result['status']);
        }

        $is_updating = false;
        if ($rule_id) {
            // Edit Rule
            foreach ($rules as &$rule) {
                if ($rule['id'] === $rule_id) {
                    $rule['title'] = $title;
                    $rule['description'] = $description;
                    $rule['category'] = $category;
                    $rule['updated_at'] = date('c');
                    $is_updating = true;
                    break;
                }
            }
            $commit_msg = "HR Policy Update: Edited '{$title}' policy";
        }

        if (!$is_updating) {
            // Create New Rule
            $new_rule = [
                'id' => 'rule_' . uniqid(),
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            $rules[] = $new_rule;
            $commit_msg = "HR Policy Update: Added '{$title}' policy";
        }

        // Commit updated rules.json to GitHub repository
        $commit_payload = [
            'message' => $commit_msg,
            'content' => base64_encode(json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'branch' => $branch
        ];
        if ($sha) {
            $commit_payload['sha'] = $sha;
        }

        $push_res = call_github_api('PUT', "/contents/rules.json", $commit_payload);
        if ($push_res['status'] === 200 || $push_res['status'] === 201) {
            send_json(['success' => true, 'rules' => $rules]);
        } else {
            send_json(['error' => 'Failed to commit policies update to GitHub.', 'details' => $push_res['body']], $push_res['status']);
        }
    }

    // Delete an HR rule
    if ($action === 'delete_rule') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json(['error' => 'Method not allowed'], 405);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $rule_id = $data['id'] ?? null;

        if (!$rule_id) {
            send_json(['error' => 'Missing Rule ID for deletion.'], 400);
        }

        // Fetch current rules.json state from GitHub
        $result = call_github_api('GET', "/contents/rules.json?ref={$branch}");
        if ($result['status'] !== 200) {
            send_json(['error' => 'Failed to access rules.json from GitHub.'], 400);
        }

        $raw_content = base64_decode($result['body']['content']);
        $rules = json_decode($raw_content, true) ?: [];
        $sha = $result['body']['sha'];

        $filtered_rules = [];
        $deleted_title = 'Unknown';
        $item_found = false;

        foreach ($rules as $rule) {
            if ($rule['id'] === $rule_id) {
                $deleted_title = $rule['title'];
                $item_found = true;
            } else {
                $filtered_rules[] = $rule;
            }
        }

        if (!$item_found) {
            send_json(['error' => 'Policy rule with provided ID not found.'], 404);
        }

        $commit_msg = "HR Policy Update: Removed '{$deleted_title}' policy";
        $commit_payload = [
            'message' => $commit_msg,
            'content' => base64_encode(json_encode($filtered_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'sha' => $sha,
            'branch' => $branch
        ];

        $push_res = call_github_api('PUT', "/contents/rules.json", $commit_payload);
        if ($push_res['status'] === 200 || $push_res['status'] === 201) {
            send_json(['success' => true, 'rules' => $filtered_rules]);
        } else {
            send_json(['error' => 'Failed to remove policy rule from GitHub.', 'details' => $push_res['body']], $push_res['status']);
        }
    }

    // Get Commit logs/ledger
    if ($action === 'get_commits') {
        $result = call_github_api('GET', "/commits?path=rules.json&sha={$branch}");
        if ($result['status'] === 200) {
            send_json($result['body']);
        } elseif ($result['status'] === 404) {
            send_json([]);
        } else {
            send_json(['error' => 'Unable to read git ledger from GitHub.', 'details' => $result['body']], $result['status']);
        }
    }

    // Reset setup configuration
    if ($action === 'reset_config') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json(['error' => 'Method not allowed'], 405);
        }
        if (getenv('OPMS_GITHUB_TOKEN')) {
            send_json(['error' => 'System is configured via environment variables. Please remove environment variables on your server to disconnect.'], 400);
        }
        if (file_exists(CONFIG_FILE)) {
            if (unlink(CONFIG_FILE)) {
                send_json(['success' => true]);
            } else {
                send_json(['error' => 'Failed to clear local configuration.'], 500);
            }
        } else {
            send_json(['success' => true]);
        }
    }

    send_json(['error' => 'Action routing endpoint not recognized.'], 404);
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        window.APP_CONFIG = {
            isConfigured: <?php echo $is_configured ? 'true' : 'false'; ?>,
            companyName: <?php echo json_encode($config['company_name'] ?? ''); ?>,
            repoOwner: <?php echo json_encode($config['repo_owner'] ?? ''); ?>,
            repoName: <?php echo json_encode($config['repo_name'] ?? ''); ?>,
            branch: <?php echo json_encode($config['branch'] ?? 'main'); ?>
        };
    </script>
    <title><?php echo $is_configured ? htmlspecialchars($config['company_name']) . " - HR Rulebook & Audit Ledger" : "HR Rulebook System Setup"; ?></title>
    
    <!-- Design Fonts: Inter, Noto Serif (English Serif), Noto Serif Bengali (Bangla Serif), JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Serif:ital,wght@0,400;0,700;1,400&family=Noto+Serif+Bengali:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- HTML2PDF CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        /* Formal light paper-based stylesheet system */
        :root {
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-surface-solid: #ffffff;
            --bg-sidebar: #0f172a; /* Slate-900 sidebar for sharp navigation contrast */
            --border-glow: rgba(59, 130, 246, 0.15);
            --border-standard: #e2e8f0;
            --color-primary: #1e3a8a; /* Deep Royal Navy */
            --color-primary-rgb: 30, 58, 138;
            --color-primary-hover: #172554;
            --color-success: #15803d; /* Forest Green */
            --color-success-glow: rgba(21, 128, 61, 0.08);
            --color-warning: #b45309; /* Bronze Amber */
            --color-warning-glow: rgba(180, 83, 9, 0.08);
            --color-danger: #be123c; /* Crimson Red */
            --color-danger-glow: rgba(190, 18, 60, 0.08);
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --sidebar-width: 260px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-paper: 'Noto Serif', 'Noto Serif Bengali', 'Georgia', serif;
            --font-mono: 'JetBrains Mono', monospace;
            --transition-speed: 0.2s;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-body);
            color: var(--text-body);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        button, input, textarea, select {
            font-family: inherit;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f8fafc;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Setup Screen styling */
        .setup-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 100vh;
            padding: 24px;
        }

        .setup-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-standard);
            border-radius: 12px;
            padding: 40px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        .setup-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--color-primary);
        }

        .setup-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .setup-logo {
            font-size: 2.2rem;
            color: var(--color-primary);
            margin-bottom: 10px;
        }

        .setup-title {
            font-family: var(--font-paper);
            font-weight: 700;
            color: var(--text-heading);
            font-size: 1.4rem;
        }

        .setup-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.5;
            margin-top: 6px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-heading);
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .form-control-wrapper {
            position: relative;
        }

        .form-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px 10px 38px;
            background: #f8fafc;
            border: 1px solid var(--border-standard);
            border-radius: 6px;
            color: var(--text-heading);
            font-size: 0.9rem;
            transition: all var(--transition-speed);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            background: #ffffff;
            box-shadow: 0 0 0 3px var(--border-glow);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all var(--transition-speed);
            width: 100%;
        }

        .btn-primary {
            background: var(--color-primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--color-primary-hover);
        }

        .btn-secondary {
            background: #ffffff;
            color: var(--text-heading);
            border: 1px solid var(--border-standard);
        }

        .btn-secondary:hover {
            background: #f8fafc;
        }

        .btn-danger {
            background: var(--color-danger);
            color: #ffffff;
        }

        .btn-danger:hover {
            background: #be123c;
        }

        .setup-help {
            margin-top: 20px;
            padding: 10px;
            background: #f8fafc;
            border-left: 3px solid var(--color-primary);
            font-size: 0.75rem;
            color: var(--text-body);
            line-height: 1.4;
        }

        /* ---------------------------------
           Main SPA Layout
        ------------------------------------ */
        .app-layout {
            display: none;
            width: 100%;
            min-height: 100vh;
        }

        /* Sidebar styling */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-sidebar);
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-logo {
            font-size: 1.35rem;
            color: #ffffff;
        }

        .sidebar-title {
            font-family: var(--font-paper);
            font-size: 1.05rem;
            font-weight: 600;
            color: #ffffff;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex-grow: 1;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all var(--transition-speed);
        }

        .menu-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.04);
        }

        .menu-link.active {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }

        /* Main Content container */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 40px;
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            background-color: var(--bg-body);
        }

        .view-section {
            display: none;
            animation: fadeIn 0.35s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            border-bottom: 1px solid var(--border-standard);
            padding-bottom: 14px;
        }

        .section-title {
            font-family: var(--font-paper);
            font-weight: 700;
            font-size: 1.8rem;
            color: var(--text-heading);
            margin-bottom: 4px;
        }

        .section-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* stats grid styling */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-standard);
            border-radius: 8px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            background: rgba(30, 58, 138, 0.05);
            color: var(--color-primary);
        }

        .stat-icon.green {
            background: var(--color-success-glow);
            color: var(--color-success);
        }

        .stat-icon.orange {
            background: var(--color-warning-glow);
            color: var(--color-warning);
        }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-val {
            font-family: var(--font-paper);
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--text-heading);
            line-height: 1.2;
        }

        .stat-lbl {
            font-size: 0.72rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 2px;
        }

        /* ---------------------------------
           Paper Document Styling (Viewer UI)
        ------------------------------------ */
        .paper-document {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            padding: 50px;
            border-radius: 4px;
            position: relative;
            font-family: var(--font-paper);
            color: #1e293b;
            line-height: 1.8;
        }

        .paper-divider {
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            height: 3px;
            margin: 20px 0;
        }

        /* Admin tables styling */
        .admin-table-container {
            background: var(--bg-surface);
            border: 1px solid var(--border-standard);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .admin-table th, .admin-table td {
            padding: 14px 20px;
        }

        .admin-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-heading);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-bottom: 1px solid var(--border-standard);
        }

        .admin-table td {
            border-bottom: 1px solid var(--border-standard);
            font-size: 0.875rem;
            vertical-align: middle;
        }

        .admin-table tr:last-child td {
            border-bottom: none;
        }

        .admin-table tr:hover td {
            background: #f8fafc;
        }

        .admin-actions {
            display: flex;
            gap: 6px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-standard);
            background: #ffffff;
            color: var(--text-body);
            cursor: pointer;
            transition: all var(--transition-speed);
        }

        .btn-icon:hover {
            color: var(--text-heading);
            background: #f1f5f9;
        }

        .btn-icon-danger:hover {
            color: #ffffff;
            background: var(--color-danger);
            border-color: var(--color-danger);
        }

        .btn-icon-primary:hover {
            color: #ffffff;
            background: var(--color-primary);
            border-color: var(--color-primary);
        }

        /* ---------------------------------
           Audit Ledger Tables
        ------------------------------------ */
        .timeline-filter-bar {
            background: var(--bg-surface);
            border: 1px solid var(--border-standard);
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .timeline-date-filters {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-input-group {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid var(--border-standard);
            border-radius: 4px;
            padding: 5px 10px;
        }

        .date-input-group label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .date-input {
            background: transparent;
            border: none;
            color: var(--text-heading);
            font-size: 0.8rem;
            outline: none;
        }

        /* ---------------------------------
           Git Connection Settings
        ------------------------------------ */
        .git-status-card {
            background: #f8fafc;
            border: 1px solid var(--border-standard);
            border-radius: 8px;
            padding: 20px;
        }

        .git-status-title {
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .git-status-dot {
            width: 6px;
            height: 6px;
            background-color: var(--color-success);
            border-radius: 50%;
            display: inline-block;
        }

        .git-status-info {
            font-family: var(--font-mono);
            color: var(--text-muted);
            margin-top: 4px;
        }

        .git-status-btn {
            background: transparent;
            border: none;
            color: var(--color-danger);
            cursor: pointer;
            font-weight: 500;
            margin-top: 8px;
            display: block;
        }

        .git-status-btn:hover {
            text-decoration: underline;
        }

        /* Modals style */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
        }

        .modal-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-standard);
            border-radius: 8px;
            width: 100%;
            max-width: 580px;
            position: relative;
            z-index: 1001;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            animation: modalSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlide {
            from { opacity: 0; transform: scale(0.97) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-standard);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-family: var(--font-paper);
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--text-heading);
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.15rem;
            cursor: pointer;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-standard);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        /* Toast notifications */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toast {
            background: #ffffff;
            border: 1px solid var(--border-standard);
            border-left: 4px solid var(--color-primary);
            border-radius: 6px;
            padding: 14px 18px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            max-width: 450px;
            font-size: 0.85rem;
            animation: toastSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes toastSlide {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.success { border-left-color: var(--color-success); }
        .toast.warning { border-left-color: var(--color-warning); }
        .toast.danger { border-left-color: var(--color-danger); }

        .toast-close {
            margin-left: auto;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 3000;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 14px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(30, 58, 138, 0.1);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            color: var(--text-heading);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .empty-placeholder {
            text-align: center;
            padding: 50px 30px;
            background: var(--bg-surface);
            border: 1px dashed var(--border-standard);
            border-radius: 8px;
            color: var(--text-muted);
        }

        .empty-placeholder-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        .empty-placeholder-title {
            font-family: var(--font-paper);
            font-weight: 600;
            font-size: 1.05rem;
            color: var(--text-heading);
            margin-bottom: 6px;
        }

        /* ---------------------------------
           Responsive adaptations
        ------------------------------------ */
        @media (max-width: 1024px) {
            body {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid var(--border-standard);
            }

            .sidebar-brand {
                padding: 16px 24px;
                justify-content: space-between;
            }

            .sidebar-menu {
                flex-direction: row;
                overflow-x: auto;
                padding: 10px 24px;
                gap: 10px;
            }

            .menu-link {
                padding: 8px 12px;
                white-space: nowrap;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 24px;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .timeline-filter-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .timeline-date-filters {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>

    <!-- Loading Spinner Overlay -->
    <div id="loading" class="loading-overlay">
        <div class="spinner"></div>
        <div id="loading-text" class="loading-text">Synchronizing Ledger with GitHub...</div>
    </div>

    <!-- Toast Notifications Container -->
    <div id="toast-container" class="toast-container"></div>

    <?php if (!$is_configured): ?>
    <!-- MODULE 1: Zero-Code Setup Screen (Gatekeeper) -->
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <i class="fa-solid fa-signature setup-logo"></i>
                <h1 class="setup-title">System Initialization</h1>
                <p class="setup-desc">Connect to your GitHub Repository to establish your stateless, versioned HR Policy Database.</p>
            </div>
            
            <form id="setup-form" onsubmit="handleSetupSubmit(event)">
                <div class="form-group">
                    <label class="form-label" for="setup-company">Company Name</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="text" id="setup-company" required placeholder="e.g. Acme Corporation">
                        <i class="fa-solid fa-building form-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="setup-token">GitHub Personal Access Token</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="password" id="setup-token" required placeholder="ghp_xxxxxxxxxxxx">
                        <i class="fa-solid fa-key form-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="setup-owner">Repository Owner</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="text" id="setup-owner" required placeholder="e.g. github_username">
                        <i class="fa-solid fa-user form-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="setup-repo">Repository Name</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="text" id="setup-repo" required placeholder="e.g. hr-policy-ledger">
                        <i class="fa-solid fa-folder form-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="setup-branch">Target Branch</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="text" id="setup-branch" value="main" required placeholder="main">
                        <i class="fa-solid fa-code-branch form-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="setup-passcode">Admin Passcode</label>
                    <div class="form-control-wrapper">
                        <input class="form-control" type="password" id="setup-passcode" value="admin" required placeholder="Default: admin">
                        <i class="fa-solid fa-lock form-icon"></i>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-circle-nodes"></i> Authenticate & Initialize
                </button>
            </form>

            <div class="setup-help">
                <strong>How to get a GitHub Token:</strong> Go to GitHub settings -> Developer Settings -> Personal Access Tokens (Tokens Classic) -> Generate a token with the <strong>repo</strong> scope.
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Application Main Layout (SPA container) -->
    <div id="app" class="app-layout">
        
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                    <i class="fa-solid fa-shield-halved sidebar-logo"></i>
                    <span class="sidebar-title" title="<?php echo htmlspecialchars($config['company_name']); ?>">
                        <?php echo htmlspecialchars($config['company_name']); ?>
                    </span>
                </div>
            </div>
            
            <ul class="sidebar-menu">
                <li>
                    <a href="#rules" class="menu-link active" onclick="navigateSPA('rules', event)">
                        <i class="fa-solid fa-book"></i> Rulebook
                    </a>
                </li>
                <li>
                    <a href="#admin" class="menu-link" onclick="navigateSPA('admin', event)">
                        <i class="fa-solid fa-gears"></i> Admin Panel
                    </a>
                </li>
                <li>
                    <a href="#timeline" class="menu-link" onclick="navigateSPA('timeline', event)">
                        <i class="fa-solid fa-receipt"></i> Change Ledger
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Workspace Contents -->
        <main class="main-content">

            <!-- MODULE 3: Frontend UI - Employee View -->
            <section id="view-rules" class="view-section" style="display: block;">
                <div class="section-header">
                    <div>
                        <h1 class="section-title">কোম্পানি নিয়মাবলী ও নির্দেশিকা</h1>
                        <p class="section-subtitle">Active and verified company policies</p>
                    </div>
                </div>

                <!-- Stats Widgets -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fa-solid fa-file-contract"></i></div>
                        <div class="stat-info">
                            <span class="stat-val" id="stat-active-rules">0</span>
                            <span class="stat-lbl">Active Policies</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fa-solid fa-shield-check"></i></div>
                        <div class="stat-info">
                            <span class="stat-val" id="stat-integrity-status">Verified</span>
                            <span class="stat-lbl">Ledger Integrity</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <div class="stat-info">
                            <span class="stat-val" id="stat-last-modified" style="font-size: 0.95rem; font-family: var(--font-mono);">-</span>
                            <span class="stat-lbl">Last Audit Date</span>
                        </div>
                    </div>
                </div>

                <div class="toolbar">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input class="search-input" type="text" id="employee-search" oninput="filterEmployeeRules()" placeholder="Search policies...">
                    </div>
                    <div class="category-filters" id="employee-category-filters">
                        <!-- Dynamic list of filters will be generated here -->
                    </div>
                </div>

                <div class="rules-grid" id="employee-rules-grid">
                    <!-- Chapter-grouped policy text will be dynamically populated here -->
                </div>
            </section>

            <!-- MODULE 2: Backend UI - Admin Panel -->
            <section id="view-admin" class="view-section">
                <div class="section-header">
                    <div>
                        <h1 class="section-title">Policy Administrator</h1>
                        <p class="section-subtitle">Add, edit, or terminate active company policies</p>
                    </div>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button class="btn btn-secondary" style="width: auto; border: 1px solid var(--border-standard); background: rgba(255,255,255,0.02);" onclick="logoutAdmin()">
                            <i class="fa-solid fa-lock"></i> Lock Console
                        </button>
                        <button class="btn btn-primary" style="width: auto;" onclick="openRuleModal()">
                            <i class="fa-solid fa-circle-plus"></i> New Policy Rule
                        </button>
                    </div>
                </div>

                <div class="admin-toolbar">
                    <div class="search-box" style="max-width: 280px;">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input class="search-input" type="text" id="admin-search" oninput="filterAdminRules()" placeholder="Filter admin list...">
                    </div>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Policy Title</th>
                                <th>Description Summary</th>
                                <th>Last Revised</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admin-rules-list">
                            <!-- Policy rows populated here -->
                        </tbody>
                    </table>
                </div>

                <!-- Admin-Only Connection Settings -->
                <div style="margin-top: 45px; max-width: 420px;">
                    <div class="git-status-card">
                        <div class="git-status-title">
                            <span class="git-status-dot"></span> Active GitOps Connection
                        </div>
                        <div class="git-status-info" title="<?php echo htmlspecialchars($config['repo_owner'] . '/' . $config['repo_name']); ?>">
                            <i class="fa-solid fa-box-archive"></i> <?php echo htmlspecialchars($config['repo_owner'] . '/' . $config['repo_name']); ?>
                        </div>
                        <div class="git-status-info">
                            <i class="fa-solid fa-code-branch"></i> Branch: <?php echo htmlspecialchars($config['branch']); ?>
                        </div>
                        <button class="git-status-btn" onclick="openResetConfigModal()">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i> Disconnect System
                        </button>
                    </div>
                </div>
            </section>

            <!-- MODULE 3: Change Log / Audit Trail View -->
            <section id="view-timeline" class="view-section">
                <div class="section-header">
                    <div>
                        <h1 class="section-title">Audit Ledger & Chain of Custody</h1>
                        <p class="section-subtitle">Chronological, cryptographically-signed policy revision trail</p>
                    </div>
                </div>

                <div class="timeline-filter-bar">
                    <div class="timeline-date-filters">
                        <div class="date-input-group">
                            <label for="filter-start-date">Start</label>
                            <input class="date-input" type="date" id="filter-start-date" onchange="filterTimelineData()">
                        </div>
                        <div class="date-input-group">
                            <label for="filter-end-date">End</label>
                            <input class="date-input" type="date" id="filter-end-date" onchange="filterTimelineData()">
                        </div>
                        <button class="btn btn-secondary" style="padding: 6px 12px; width: auto; font-size: 0.8125rem;" onclick="clearDateFilters()">
                            Reset Filters
                        </button>
                    </div>
                    <!-- MODULE 4: Legal Export Button -->
                    <button class="btn btn-primary" style="width: auto; background: var(--color-success); box-shadow: 0 4px 14px rgba(21, 128, 61, 0.15);" onclick="generateLegalPDF()">
                        <i class="fa-solid fa-file-pdf"></i> Export Court-Ready Ledger
                    </button>
                </div>

                <div id="timeline-contents">
                    <!-- Ruled Registry Journal populated here -->
                </div>
            </section>

        </main>
    </div>

    <!-- Modals -->

    <!-- Add/Edit Rule Modal -->
    <div class="modal" id="rule-modal">
        <div class="modal-overlay" onclick="closeRuleModal()"></div>
        <div class="modal-card">
            <div class="modal-header">
                <h3 class="modal-title" id="rule-modal-title">Create New Policy</h3>
                <button class="modal-close" onclick="closeRuleModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <form id="rule-form">
                    <input type="hidden" id="rule-id-input">
                    <div class="form-group">
                        <label class="form-label" for="rule-title-input">Policy Title</label>
                        <div class="form-control-wrapper">
                            <input class="form-control" style="padding-left: 14px;" type="text" id="rule-title-input" required placeholder="e.g. Remote Work Framework">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rule-category-input">Category Classification</label>
                        <div class="form-control-wrapper">
                            <select class="form-control" style="padding-left: 14px;" id="rule-category-input" required>
                                <option value="Operations">Operations</option>
                                <option value="Human Resources">Human Resources</option>
                                <option value="Legal & Compliance">Legal & Compliance</option>
                                <option value="Security">Security</option>
                                <option value="Code of Conduct">Code of Conduct</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="rule-desc-input">Policy Body & Regulations</label>
                        <div class="form-control-wrapper">
                            <textarea class="form-control" style="padding-left: 14px; min-height: 180px; resize: vertical; font-family: var(--font-paper);" id="rule-desc-input" required placeholder="Describe details, policies, compliance protocols, and employee obligations..."></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" style="width: auto;" onclick="closeRuleModal()">Cancel</button>
                <button class="btn btn-primary" style="width: auto;" onclick="submitRuleForm()">
                    <i class="fa-solid fa-floppy-disk"></i> Commit Policy
                </button>
            </div>
        </div>
    </div>

    <!-- Admin Passcode Modal -->
    <div class="modal" id="passcode-modal">
        <div class="modal-overlay" onclick="cancelAdminAuth()"></div>
        <div class="modal-card" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">Admin Access Required</h3>
                <button class="modal-close" onclick="cancelAdminAuth()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <form id="passcode-form" onsubmit="submitAdminAuth(event)">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="admin-passcode-input">Enter Admin Passcode</label>
                        <div class="form-control-wrapper">
                            <input class="form-control" type="password" id="admin-passcode-input" required placeholder="••••••••">
                            <i class="fa-solid fa-lock form-icon"></i>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" style="width: auto;" onclick="cancelAdminAuth()">Cancel</button>
                <button class="btn btn-primary" style="width: auto;" onclick="submitAdminAuth(event)">
                    <i class="fa-solid fa-key"></i> Authenticate
                </button>
            </div>
        </div>
    </div>

    <!-- Rule Details Modal -->
    <div class="modal" id="details-modal">
        <div class="modal-overlay" onclick="closeDetailsModal()"></div>
        <div class="modal-card" style="max-width: 650px;">
            <div class="modal-header">
                <div>
                    <span class="rule-badge" id="details-category-badge" style="margin-bottom: 8px;">Category</span>
                    <h3 class="modal-title" id="details-title" style="margin-top: 4px;">Policy Title</h3>
                </div>
                <button class="modal-close" onclick="closeDetailsModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="font-size: 0.95rem; line-height: 1.7; color: var(--text-heading); font-family: var(--font-paper);">
                <div id="details-description" style="white-space: pre-wrap; text-align: justify;">Policy content...</div>
            </div>
            <div class="modal-footer" style="justify-content: space-between; align-items: center;">
                <span class="commit-date" id="details-dates" style="font-size: 0.75rem;">Created: - | Updated: -</span>
                <button class="btn btn-secondary" style="width: auto;" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm Policy Deletion Modal -->
    <div class="modal" id="delete-modal">
        <div class="modal-overlay" onclick="closeDeleteModal()"></div>
        <div class="modal-card" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">Terminate Policy</h3>
                <button class="modal-close" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 32px 24px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: var(--color-danger); margin-bottom: 16px;"></i>
                <p style="color: var(--text-heading); font-weight: 600; margin-bottom: 12px; font-size: 1.1rem;">Are you absolutely sure?</p>
                <p style="font-size: 0.875rem; line-height: 1.5;">This will permanently remove the <strong id="delete-policy-name">policy</strong> from the current active roster. This termination event will be committed as an unalterable audit log on GitHub.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" style="width: auto;" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" style="width: auto;" id="confirm-delete-btn">
                    <i class="fa-solid fa-trash-can"></i> Terminate & Commit
                </button>
            </div>
        </div>
    </div>

    <!-- Disconnect System Modal -->
    <div class="modal" id="reset-modal">
        <div class="modal-overlay" onclick="closeResetConfigModal()"></div>
        <div class="modal-card" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">Disconnect System</h3>
                <button class="modal-close" onclick="closeResetConfigModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 32px 24px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 3rem; color: var(--color-warning); margin-bottom: 16px;"></i>
                <p style="color: var(--text-heading); font-weight: 600; margin-bottom: 12px; font-size: 1.1rem;">Clear Local Connection?</p>
                <p style="font-size: 0.875rem; line-height: 1.5;">This will delete the local `config.json` configuration file. The remote repository and rule files will remain intact, but you must re-enter your setup settings to access the app again.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" style="width: auto;" onclick="closeResetConfigModal()">Cancel</button>
                <button class="btn btn-danger" style="width: auto; background-color: var(--color-warning); color: #000;" onclick="submitResetConfig()">
                    Confirm Disconnection
                </button>
            </div>
        </div>
    </div>

    <!-- Dynamic Container for Legal PDF Generation -->
    <div id="pdf-export-temp"></div>

    <?php endif; ?>

    <!-- Client-Side State and Controller Logic -->
    <script>
        // Global variables initialized from PHP State
        const APP_CONFIG = window.APP_CONFIG || { isConfigured: false };

        // Client Cache
        let rulesCache = [];
        let commitsCache = [];
        let currentRulesSha = null;
        let selectedCategoryFilter = 'All';
        let isAdminAuthenticated = false;
        let activeView = 'rules';

        // SPA Navigation Router
        function navigateSPA(targetView, event) {
            if (event) event.preventDefault();

            // Intercept Admin Access
            if (targetView === 'admin' && !isAdminAuthenticated) {
                document.getElementById('admin-passcode-input').value = '';
                document.getElementById('passcode-modal').style.display = 'flex';
                document.getElementById('admin-passcode-input').focus();
                window.location.hash = activeView;
                return;
            }

            activeView = targetView;
            window.location.hash = targetView;

            // Toggle Navbar Menu Link active state
            document.querySelectorAll('.menu-link').forEach(link => {
                if (link.getAttribute('href') === '#' + targetView) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });

            // Toggle view visibility
            document.querySelectorAll('.view-section').forEach(view => {
                if (view.id === 'view-' + targetView) {
                    view.style.display = 'block';
                } else {
                    view.style.display = 'none';
                }
            });

            // Trigger fetches based on page focus
            if (targetView === 'rules') {
                loadRulebookData();
            } else if (targetView === 'admin') {
                loadAdminData();
            } else if (targetView === 'timeline') {
                loadTimelineData();
            }
        }

        function cancelAdminAuth() {
            document.getElementById('passcode-modal').style.display = 'none';
        }

        async function submitAdminAuth(event) {
            if (event) event.preventDefault();
            const passcode = document.getElementById('admin-passcode-input').value.trim();
            if (!passcode) return;

            toggleLoading(true, "Verifying credentials...");
            try {
                const res = await fetchAPI('?action=verify_admin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ passcode })
                });
                if (res.success) {
                    isAdminAuthenticated = true;
                    document.getElementById('passcode-modal').style.display = 'none';
                    showToast("Admin access granted.", "success");
                    navigateSPA('admin');
                }
            } catch (e) {
                // error is automatically displayed by fetchAPI wrapper
            } finally {
                toggleLoading(false);
            }
        }

        function logoutAdmin() {
            isAdminAuthenticated = false;
            showToast("Admin console locked successfully.", "info");
            navigateSPA('rules');
        }

        // Loading overlay utility
        function toggleLoading(show, text = "Loading...") {
            const overlay = document.getElementById('loading');
            const overlayText = document.getElementById('loading-text');
            if (overlay) {
                overlayText.innerText = text;
                overlay.style.display = show ? 'flex' : 'none';
            }
        }

        // Toast notification system
        function showToast(message, type = "info") {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            let icon = 'fa-info-circle';
            if (type === 'success') icon = 'fa-check-circle';
            if (type === 'warning') icon = 'fa-exclamation-triangle';
            if (type === 'danger') icon = 'fa-circle-exclamation';

            toast.innerHTML = `
                <i class="fa-solid ${icon}"></i>
                <span>${message}</span>
                <button class="toast-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>
            `;

            container.appendChild(toast);
            
            // Auto close toast
            setTimeout(() => {
                toast.style.animation = 'toastSlide 0.25s ease-out reverse';
                setTimeout(() => toast.remove(), 250);
            }, 4000);
        }

        // HTTP Fetch API wrapper
        async function fetchAPI(url, options = {}) {
            try {
                const response = await fetch(url, options);
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Network error occurred.');
                }
                return data;
            } catch (err) {
                showToast(err.message, 'danger');
                throw err;
            }
        }

        /* MODULE 1: Setup screen logic */
        async function handleSetupSubmit(event) {
            event.preventDefault();
            
            const company = document.getElementById('setup-company').value.trim();
            const token = document.getElementById('setup-token').value.trim();
            const owner = document.getElementById('setup-owner').value.trim();
            const repo = document.getElementById('setup-repo').value.trim();
            const branch = document.getElementById('setup-branch').value.trim() || 'main';
            const passcode = document.getElementById('setup-passcode').value.trim() || 'admin';

            toggleLoading(true, "Authenticating GitHub Credentials...");

            try {
                const result = await fetchAPI('?action=save_setup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        github_token: token,
                        repo_owner: owner,
                        repo_name: repo,
                        company_name: company,
                        branch: branch,
                        admin_passcode: passcode
                    })
                });

                if (result.success) {
                    showToast("Authentication Successful! Initializing Application...", "success");
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            } catch (err) {
                toggleLoading(false);
            }
        }

        // Reset Settings Configuration
        function openResetConfigModal() {
            document.getElementById('reset-modal').style.display = 'flex';
        }
        function closeResetConfigModal() {
            document.getElementById('reset-modal').style.display = 'none';
        }
        async function submitResetConfig() {
            closeResetConfigModal();
            toggleLoading(true, "Disconnecting Repository...");
            try {
                const res = await fetchAPI('?action=reset_config', { method: 'POST' });
                if (res.success) {
                    window.location.reload();
                }
            } catch (e) {
                toggleLoading(false);
            }
        }

        /* MODULE 3: Rules / Employee Portal Data */
        async function loadRulebookData() {
            toggleLoading(true, "Synchronizing Policies...");
            try {
                const res = await fetchAPI('?action=get_rules');
                rulesCache = res.rules;
                currentRulesSha = res.sha;

                // Sync UI
                document.getElementById('stat-active-rules').innerText = rulesCache.length;
                renderEmployeeRules();
                renderCategoryFilters();
                updateLastAuditDate();
            } catch (e) {
                // error handled in fetchAPI wrapper toast
            } finally {
                toggleLoading(false);
            }
        }

        function renderEmployeeRules() {
            const container = document.getElementById('employee-rules-grid');
            if (!container) return;
            container.innerHTML = '';

            const searchVal = document.getElementById('employee-search').value.toLowerCase().trim();

            // Filter rules first
            const filtered = rulesCache.filter(rule => {
                const matchSearch = rule.title.toLowerCase().includes(searchVal) || 
                                    rule.description.toLowerCase().includes(searchVal) ||
                                    rule.category.toLowerCase().includes(searchVal);
                const matchCategory = selectedCategoryFilter === 'All' || rule.category === selectedCategoryFilter;
                return matchSearch && matchCategory;
            });

            if (filtered.length === 0) {
                container.innerHTML = `
                    <div class="empty-placeholder" style="width: 100%;">
                        <div class="empty-placeholder-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <div class="empty-placeholder-title">কোনো নিয়মাবলী পাওয়া যায়নি (No rules found)</div>
                        <p>অনুগ্রহ করে আপনার অনুসন্ধানের শব্দ পরিবর্তন করে চেষ্টা করুন</p>
                    </div>
                `;
                return;
            }

            // Group by category (Chapter-wise)
            const groups = {};
            filtered.forEach(rule => {
                if (!groups[rule.category]) {
                    groups[rule.category] = [];
                }
                groups[rule.category].push(rule);
            });

            const sortedCategories = Object.keys(groups).sort();

            // Build the Policy Document container
            const docElement = document.createElement('div');
            docElement.className = 'paper-document';
            docElement.style.width = '100%';

            // Document Header
            let html = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <div style="font-family: var(--font-paper); font-size: 1.8rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;">কোম্পানি নিয়মাবলী ও নির্দেশিকা</div>
                    <div style="font-family: var(--font-paper); font-size: 1.1rem; color: var(--text-muted); margin-top: 5px; font-style: italic;">অফিসিয়াল পলিসি ম্যানুয়াল - ${escapeHtml(APP_CONFIG.companyName)}</div>
                    <div class="paper-divider"></div>
                </div>
            `;

            // Table of Contents (সূচীপত্র)
            html += `
                <div style="margin-bottom: 40px; background: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    <h4 style="font-family: var(--font-paper); font-weight: 700; font-size: 1.1rem; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; color: var(--text-heading);">সূচীপত্র (Table of Contents)</h4>
                    <ul style="list-style: none; padding-left: 0;">
            `;
            sortedCategories.forEach((cat, index) => {
                const chapterNum = index + 1;
                html += `
                    <li style="margin-bottom: 8px; display: flex; justify-content: space-between; font-size: 0.95rem;">
                        <a href="#chapter-${chapterNum}" style="color: var(--color-primary); text-decoration: none; font-family: var(--font-paper); font-weight: 600;" onclick="scrollToChapter(event, 'chapter-${chapterNum}')">
                            অধ্যায় ${chapterNum}: ${escapeHtml(cat)}
                        </a>
                        <span style="border-bottom: 1px dotted #cbd5e1; flex-grow: 1; margin: 0 10px; margin-bottom: 4px;"></span>
                        <span style="font-family: var(--font-mono); color: var(--text-muted); font-size: 0.85rem;">${groups[cat].length} টি নিয়ম</span>
                    </li>
                `;
            });
            html += `
                    </ul>
                </div>
            `;

            // Render Chapters and Sections
            sortedCategories.forEach((cat, catIndex) => {
                const chapterNum = catIndex + 1;
                html += `
                    <div id="chapter-${chapterNum}" style="margin-top: 40px; scroll-margin-top: 20px;">
                        <h3 style="font-family: var(--font-paper); font-weight: 700; font-size: 1.4rem; color: var(--text-heading); border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 24px;">
                            অধ্যায় ${chapterNum}: ${escapeHtml(cat)}
                        </h3>
                `;

                groups[cat].forEach((rule, ruleIndex) => {
                    const sectionNum = `${chapterNum}.${ruleIndex + 1}`;
                    const updatedDate = new Date(rule.updated_at).toLocaleDateString(undefined, {
                        year: 'numeric', month: 'short', day: 'numeric'
                    });

                    html += `
                        <div style="margin-bottom: 30px; padding-left: 14px; border-left: 3px solid #cbd5e1;">
                            <h4 style="font-family: var(--font-paper); font-weight: 600; font-size: 1.15rem; color: var(--text-heading); margin-bottom: 8px; display: flex; align-items: baseline; gap: 8px;">
                                <span style="font-family: var(--font-mono); color: var(--color-primary);">§ ${sectionNum}</span>
                                <a onclick="openDetailsModal('${rule.id}')" style="color: inherit; text-decoration: none; cursor: pointer;">
                                    ${escapeHtml(rule.title)}
                                </a>
                            </h4>
                            <div style="font-family: var(--font-paper); font-size: 0.95rem; color: #334155; text-align: justify; white-space: pre-wrap; margin-bottom: 10px;">${escapeHtml(rule.description)}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); display: flex; gap: 16px;">
                                <span>সংশোধনের তারিখ (Revised): ${updatedDate}</span>
                                <span style="font-family: var(--font-mono);">ID: ${rule.id}</span>
                            </div>
                        </div>
                    `;
                });

                html += `</div>`;
            });

            docElement.innerHTML = html;
            container.appendChild(docElement);
        }

        function scrollToChapter(event, id) {
            if (event) event.preventDefault();
            const el = document.getElementById(id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth' });
            }
        }

        function renderCategoryFilters() {
            const container = document.getElementById('employee-category-filters');
            if (!container) return;

            // Gather unique categories
            const categories = new Set();
            rulesCache.forEach(r => categories.add(r.category));

            container.innerHTML = '';
            
            // Add "All" selector
            const allBtn = document.createElement('span');
            allBtn.className = `filter-tag ${selectedCategoryFilter === 'All' ? 'active' : ''}`;
            allBtn.innerText = 'All Policies';
            allBtn.onclick = () => {
                selectedCategoryFilter = 'All';
                document.querySelectorAll('.filter-tag').forEach(b => b.classList.remove('active'));
                allBtn.classList.add('active');
                renderEmployeeRules();
            };
            container.appendChild(allBtn);

            // Add dynamic category pills
            categories.forEach(cat => {
                const btn = document.createElement('span');
                btn.className = `filter-tag ${selectedCategoryFilter === cat ? 'active' : ''}`;
                btn.innerText = cat;
                btn.onclick = () => {
                    selectedCategoryFilter = cat;
                    document.querySelectorAll('.filter-tag').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    renderEmployeeRules();
                };
                container.appendChild(btn);
            });
        }

        function filterEmployeeRules() {
            renderEmployeeRules();
        }

        async function updateLastAuditDate() {
            try {
                // Fetch commit ledger to read latest date
                const commits = await fetchAPI('?action=get_commits');
                if (commits && commits.length > 0) {
                    const latestCommit = commits[0];
                    const commitDate = new Date(latestCommit.commit.author.date);
                    document.getElementById('stat-last-modified').innerText = commitDate.toLocaleDateString(undefined, {
                        month: 'short', day: 'numeric', year: '2-digit'
                    });
                } else {
                    document.getElementById('stat-last-modified').innerText = "None";
                }
            } catch(e) {
                // Ignore silently
            }
        }

        // Details Modal view
        function openDetailsModal(id) {
            const rule = rulesCache.find(r => r.id === id);
            if (!rule) return;

            document.getElementById('details-category-badge').innerText = rule.category;
            document.getElementById('details-title').innerText = rule.title;
            document.getElementById('details-description').innerText = rule.description;
            
            const created = new Date(rule.created_at).toLocaleString();
            const updated = new Date(rule.updated_at).toLocaleString();
            document.getElementById('details-dates').innerText = `Enacted: ${created} | Revised: ${updated}`;

            document.getElementById('details-modal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('details-modal').style.display = 'none';
        }

        /* MODULE 2: Admin Panel Logic */
        async function loadAdminData() {
            toggleLoading(true, "Synchronizing Admin Console...");
            try {
                const res = await fetchAPI('?action=get_rules');
                rulesCache = res.rules;
                currentRulesSha = res.sha;
                renderAdminRulesList();
            } catch(e) {}
            finally {
                toggleLoading(false);
            }
        }

        function renderAdminRulesList() {
            const tbody = document.getElementById('admin-rules-list');
            if (!tbody) return;
            tbody.innerHTML = '';

            const searchVal = document.getElementById('admin-search').value.toLowerCase().trim();
            const filtered = rulesCache.filter(rule => 
                rule.title.toLowerCase().includes(searchVal) || 
                rule.category.toLowerCase().includes(searchVal) || 
                rule.description.toLowerCase().includes(searchVal)
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="empty-placeholder" style="text-align: center; padding: 40px;">
                            <div class="empty-placeholder-icon" style="font-size: 2rem;"><i class="fa-solid fa-folder-open"></i></div>
                            <div class="empty-placeholder-title">No policies in registry</div>
                            <p>Create a new policy using the header button</p>
                        </td>
                    </tr>
                `;
                return;
            }

            filtered.forEach(rule => {
                const tr = document.createElement('tr');
                const updateDate = new Date(rule.updated_at).toLocaleDateString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                // Snippet description to limit width
                const shortDesc = rule.description.length > 80 ? rule.description.substring(0, 80) + '...' : rule.description;

                tr.innerHTML = `
                    <td><span class="rule-badge" style="margin-bottom:0; font-size: 0.7rem; background: rgba(30,58,138,0.05); border: 1px solid rgba(30,58,138,0.1); color: var(--color-primary);">${escapeHtml(rule.category)}</span></td>
                    <td style="font-weight: 600; color: var(--text-heading);">${escapeHtml(rule.title)}</td>
                    <td style="color: var(--text-body); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(shortDesc)}</td>
                    <td style="color: var(--text-muted); font-size: 0.8rem;">${updateDate}</td>
                    <td style="text-align: right;">
                        <div class="admin-actions" style="justify-content: flex-end;">
                            <button class="btn-icon btn-icon-primary" title="Edit Policy" onclick="openRuleModal('${rule.id}')">
                                <i class="fa-solid fa-pencil"></i>
                            </button>
                            <button class="btn-icon btn-icon-danger" title="Terminate Policy" onclick="triggerDeleteRule('${rule.id}')">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function filterAdminRules() {
            renderAdminRulesList();
        }

        // Add / Edit Rule Dialog Form
        function openRuleModal(id = null) {
            const modal = document.getElementById('rule-modal');
            const titleEl = document.getElementById('rule-modal-title');
            
            // Clear Form
            document.getElementById('rule-form').reset();
            document.getElementById('rule-id-input').value = '';

            if (id) {
                // Edit mode
                const rule = rulesCache.find(r => r.id === id);
                if (rule) {
                    titleEl.innerText = "Revise Company Policy";
                    document.getElementById('rule-id-input').value = rule.id;
                    document.getElementById('rule-title-input').value = rule.title;
                    document.getElementById('rule-category-input').value = rule.category;
                    document.getElementById('rule-desc-input').value = rule.description;
                }
            } else {
                titleEl.innerText = "Draft New Policy";
            }
            modal.style.display = 'flex';
        }

        function closeRuleModal() {
            document.getElementById('rule-modal').style.display = 'none';
        }

        async function submitRuleForm() {
            const form = document.getElementById('rule-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const id = document.getElementById('rule-id-input').value;
            const title = document.getElementById('rule-title-input').value.trim();
            const category = document.getElementById('rule-category-input').value;
            const description = document.getElementById('rule-desc-input').value.trim();

            closeRuleModal();
            toggleLoading(true, "Securing Audit revision & committing changes to GitHub...");

            try {
                const res = await fetchAPI('?action=save_rule', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, title, category, description })
                });

                if (res.success) {
                    showToast(id ? "Policy modified and committed successfully!" : "New policy drafted and committed successfully!", "success");
                    loadAdminData();
                }
            } catch(e) {}
            finally {
                toggleLoading(false);
            }
        }

        // Delete Policy
        let ruleIdToDelete = null;
        function triggerDeleteRule(id) {
            const rule = rulesCache.find(r => r.id === id);
            if (!rule) return;

            ruleIdToDelete = id;
            document.getElementById('delete-policy-name').innerText = `"${rule.title}"`;
            document.getElementById('delete-modal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').style.display = 'none';
            ruleIdToDelete = null;
        }

        // Hook up delete click confirmation
        document.getElementById('confirm-delete-btn').addEventListener('click', async () => {
            if (!ruleIdToDelete) return;
            const id = ruleIdToDelete;
            closeDeleteModal();

            toggleLoading(true, "Publishing revocation and updating ledger on GitHub...");
            try {
                const res = await fetchAPI('?action=delete_rule', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });

                if (res.success) {
                    showToast("Policy successfully terminated and logged in ledger.", "success");
                    loadAdminData();
                }
            } catch(e) {}
            finally {
                toggleLoading(false);
            }
        });

        /* MODULE 3: Timeline & Audit Ledger */
        async function loadTimelineData() {
            toggleLoading(true, "Accessing Git Revision Ledger...");
            try {
                const commits = await fetchAPI('?action=get_commits');
                commitsCache = commits;
                renderTimeline();
            } catch(e) {}
            finally {
                toggleLoading(false);
            }
        }

        function renderTimeline() {
            const container = document.getElementById('timeline-contents');
            if (!container) return;
            container.innerHTML = '';

            const startDateVal = document.getElementById('filter-start-date').value;
            const endDateVal = document.getElementById('filter-end-date').value;

            const startLimit = startDateVal ? new Date(startDateVal + 'T00:00:00') : null;
            const endLimit = endDateVal ? new Date(endDateVal + 'T23:59:59') : null;

            // Filter commits based on date inputs
            const filteredCommits = commitsCache.filter(commitObj => {
                const date = new Date(commitObj.commit.author.date);
                if (startLimit && date < startLimit) return false;
                if (endLimit && date > endLimit) return false;
                return true;
            });

            if (filteredCommits.length === 0) {
                container.innerHTML = `
                    <div class="empty-placeholder">
                        <div class="empty-placeholder-icon"><i class="fa-solid fa-receipt"></i></div>
                        <div class="empty-placeholder-title">কোনো সংশোধনীর রেকর্ড পাওয়া যায়নি</div>
                        <p>নির্বাচিত তারিখের মধ্যে কোনো সংশোধনী লগের রেকর্ড নেই।</p>
                    </div>
                `;
                return;
            }

            // Create Paper Document for the Ledger Table
            const paperDoc = document.createElement('div');
            paperDoc.className = 'paper-document';
            paperDoc.style.padding = '35px 25px';

            let html = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <div style="font-family: var(--font-paper); font-size: 1.5rem; font-weight: 700; text-transform: uppercase;">অফিসিয়াল সংশোধনী রেজিস্টার ও প্রমাণপত্র</div>
                    <div style="font-family: var(--font-paper); font-size: 0.95rem; color: var(--text-muted); margin-top: 5px;">অপরিবর্তনীয় সংশোধন ট্রেইল এবং ডিজিটাল সিগনেচার রেকর্ড</div>
                    <div class="paper-divider" style="margin: 16px 0;"></div>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-family: var(--font-paper); font-size: 0.9rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid #111; text-align: left; background: #f8fafc;">
                                <th style="padding: 12px; font-weight: 700; width: 15%;">রেজিস্ট্রি নং (Registry ID)</th>
                                <th style="padding: 12px; font-weight: 700; width: 20%;">তারিখ ও সময় (Date & Time)</th>
                                <th style="padding: 12px; font-weight: 700; width: 25%;">দায়িত্বপ্রাপ্ত কর্মকর্তা (Officer)</th>
                                <th style="padding: 12px; font-weight: 700; width: 25%;">সংশোধনীর বিবরণ (Amendment Details)</th>
                                <th style="padding: 12px; font-weight: 700; text-align: right; width: 15%;">ডিজিটাল ভেরিফিকেশন (SHA-1)</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            filteredCommits.forEach(commitObj => {
                const msg = commitObj.commit.message;
                const author = commitObj.commit.author.name;
                const authorEmail = commitObj.commit.author.email;
                const dateStr = new Date(commitObj.commit.author.date).toLocaleString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                const hash = commitObj.sha;
                const shortHash = hash.substring(0, 7);

                html += `
                    <tr style="border-bottom: 1px solid #e2e8f0; transition: background 0.1s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <td style="padding: 14px 12px; font-family: var(--font-mono); font-size: 0.78rem; font-weight: 600; color: #1e3a8a;">
                            REG-${shortHash.toUpperCase()}
                        </td>
                        <td style="padding: 14px 12px; white-space: nowrap; color: #334155;">
                            ${dateStr}
                        </td>
                        <td style="padding: 14px 12px; color: #0f172a; font-weight: 500;">
                            <div>${escapeHtml(author)}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400; font-family: var(--font-sans);">${escapeHtml(authorEmail)}</div>
                        </td>
                        <td style="padding: 14px 12px; color: #334155; text-align: justify;">
                            <div style="font-weight: 600; color: #0f172a; font-size: 0.95rem;">${escapeHtml(msg)}</div>
                        </td>
                        <td style="padding: 14px 12px; text-align: right; font-family: var(--font-mono); font-size: 0.75rem;">
                            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                                <a href="https://github.com/${APP_CONFIG.repoOwner}/${APP_CONFIG.repoName}/commit/${hash}" target="_blank" style="color: var(--color-primary); text-decoration: none;" title="GitHub-এ যাচাই করুন">
                                    ${shortHash}
                                </a>
                                <button class="btn-copy" onclick="copyToClipboard('${hash}', this)" style="padding: 3px 6px; border: 1px solid var(--border-standard); background: #ffffff; cursor: pointer; border-radius: 4px; color: var(--text-muted);" title="সিগনেচার কপি করুন">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            paperDoc.innerHTML = html;
            container.appendChild(paperDoc);
        }

        function clearDateFilters() {
            document.getElementById('filter-start-date').value = '';
            document.getElementById('filter-end-date').value = '';
            renderTimeline();
        }

        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                showToast("Cryptographic signature copied to clipboard!", "success");
                const icon = btn.querySelector('i');
                icon.className = 'fa-solid fa-check';
                icon.style.color = 'var(--color-success)';
                setTimeout(() => {
                    icon.className = 'fa-solid fa-copy';
                    icon.style.color = '';
                }, 1500);
            }).catch(() => {
                showToast("Failed to copy signature automatically.", "warning");
            });
        }

        /* MODULE 4: Legal Export PDF Generation */
        function generateLegalPDF() {
            const container = document.getElementById('pdf-export-temp');
            if (!container) return;

            toggleLoading(true, "Formatting court-ready evidence ledger...");

            // Reconstruct timeline array matching the current UI state filter
            const startDateVal = document.getElementById('filter-start-date').value;
            const endDateVal = document.getElementById('filter-end-date').value;

            const startLimit = startDateVal ? new Date(startDateVal + 'T00:00:00') : null;
            const endLimit = endDateVal ? new Date(endDateVal + 'T23:59:59') : null;

            const filteredCommits = commitsCache.filter(commitObj => {
                const date = new Date(commitObj.commit.author.date);
                if (startLimit && date < startLimit) return false;
                if (endLimit && date > endLimit) return false;
                return true;
            });

            if (filteredCommits.length === 0) {
                showToast("Cannot generate PDF: No ledger history matches filters.", "warning");
                toggleLoading(false);
                return;
            }

            const currentTimestamp = new Date().toLocaleString();
            
            // Build the Formal court-ready layout with support for Noto Serif Bengali
            let htmlContent = `
                <div class="pdf-export-container" style="font-family: 'Times New Roman', 'Noto Serif Bengali', serif;">
                    <div class="pdf-cover-border">
                        <div class="pdf-legal-title">HR Policy Ledger & Chain of Custody</div>
                        <div class="pdf-legal-subtitle">Verification Transcript of Immutable Records (সংশোধনী রেজিস্টার)</div>
                        
                        <table class="pdf-ledger-meta">
                            <tr>
                                <td><strong>Organization</strong></td>
                                <td>${escapeHtml(APP_CONFIG.companyName)}</td>
                                <td><strong>Document Enactment</strong></td>
                                <td>${currentTimestamp}</td>
                            </tr>
                            <tr>
                                <td><strong>Source Repository</strong></td>
                                <td>github.com/${escapeHtml(APP_CONFIG.repoOwner)}/${escapeHtml(APP_CONFIG.repoName)}</td>
                                <td><strong>Storage Path</strong></td>
                                <td>/rules.json</td>
                            </tr>
                            <tr>
                                <td><strong>Target Branch</strong></td>
                                <td>${escapeHtml(APP_CONFIG.branch)}</td>
                                <td><strong>Verified Records</strong></td>
                                <td>${filteredCommits.length} revisions listed</td>
                            </tr>
                        </table>
                    </div>

                    <div class="pdf-disclaimer">
                        <strong>LEGAL COMPLIANCE & PROOF OF CUSTODY NOTICE:</strong> This transcript contains a chronologically
                        verifiable history of company policy updates and revocations retrieved directly from git-versioned storage.
                        Each record is mathematically secured using Git SHA-1 cryptographic hashes. Under current repository configuration,
                        branch force-pushes and history deletions are disabled, ensuring that this ledger represents an unaltered,
                        100% immutable record. The signature blocks below certify the validity of this transcript for corporate auditing or court procedures.
                    </div>

                    <h3 style="font-family: 'Times New Roman', 'Noto Serif Bengali', serif; font-size: 14px; text-transform: uppercase; margin-bottom: 10px; border-bottom: 1px solid #111; padding-bottom: 5px;">
                        Verifiable Timeline Log (সংশোধনী লগ)
                    </h3>

                    <table class="pdf-timeline-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Timestamp (UTC)</th>
                                <th style="width: 20%;">Authorized By</th>
                                <th style="width: 20%;">Action Class</th>
                                <th style="width: 35%;">Action Log Details / Cryptographic signature</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            filteredCommits.forEach(commitObj => {
                const msg = commitObj.commit.message;
                const author = commitObj.commit.author.name;
                const dateStr = new Date(commitObj.commit.author.date).toISOString().replace('T', ' ').substring(0, 19);
                const hash = commitObj.sha;

                let actionLabel = 'Revision';
                if (msg.includes('Added')) actionLabel = 'Enacted (ADD)';
                else if (msg.includes('Removed')) actionLabel = 'Terminated (DELETE)';
                else if (msg.includes('Edited')) actionLabel = 'Revised (EDIT)';

                htmlContent += `
                    <tr>
                        <td>${dateStr}</td>
                        <td>${escapeHtml(author)}</td>
                        <td><span class="pdf-action-label">${actionLabel}</span></td>
                        <td>
                            <div style="font-weight: bold; margin-bottom: 6px;">${escapeHtml(msg)}</div>
                            <div style="font-size: 9px; color: #555;">SHA-1 SIGNATURE:</div>
                            <div class="pdf-hash-text">${hash}</div>
                        </td>
                    </tr>
                `;
            });

            htmlContent += `
                        </tbody>
                    </table>

                    <div class="pdf-sig-block">
                        <div class="pdf-sig-col">
                            <strong>HR Director / Authorized Representative</strong>
                            <div style="margin-top: 35px; border-bottom: 1px dashed #666; width: 80%; margin-left: auto; margin-right: auto;"></div>
                            <div style="font-size: 10px; color: #555; margin-top: 5px;">Authorized Signature & Date</div>
                        </div>
                        <div class="pdf-sig-col">
                            <strong>Legal Counsel / Auditor Witness</strong>
                            <div style="margin-top: 35px; border-bottom: 1px dashed #666; width: 80%; margin-left: auto; margin-right: auto;"></div>
                            <div style="font-size: 10px; color: #555; margin-top: 5px;">Audited Signature & Date</div>
                        </div>
                    </div>

                    <div style="margin-top: 40px; text-align: center; font-size: 9px; color: #666; border-top: 1px solid #eee; padding-top: 15px;">
                        Immutable HR Evidence Ledger - Page 1 of 1 (Secure Hash: ${filteredCommits[0].sha.substring(0, 10)}...${filteredCommits[filteredCommits.length - 1].sha.substring(0, 10)})
                    </div>
                </div>
            `;

            container.innerHTML = htmlContent;

            // Generate PDF via html2pdf
            const filename = `Immutable_HR_Ledger_${APP_CONFIG.companyName.replace(/[^a-z0-9]/gi, '_')}_${new Date().toISOString().split('T')[0]}.pdf`;
            const opt = {
                margin:       0.3,
                filename:     filename,
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2.5, useCORS: true },
                jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
            };

            html2pdf().set(opt).from(container).save().then(() => {
                container.innerHTML = '';
                toggleLoading(false);
                showToast("Court-ready evidence PDF exported successfully!", "success");
            }).catch(err => {
                toggleLoading(false);
                showToast("Failed to render PDF: " + err.message, "danger");
            });
        }

        // MD5 Hash generator for Gravatar fallback
        function md5(string) {
            function RotateLeft(lValue, iShiftBits) {
                return (lValue<<iShiftBits) | (lValue>>>(32-iShiftBits));
            }
            function AddUnsigned(lX,lY) {
                var lX4,lY4,lX8,lY8,lResult;
                lX8 = (lX & 0x80000000);
                lY8 = (lY & 0x80000000);
                lX4 = (lX & 0x40000000);
                lY4 = (lY & 0x40000000);
                lResult = (lX & 0x3FFFFFFF)+(lY & 0x3FFFFFFF);
                if (lX4 & lY4) return (lResult ^ 0x80000000 ^ lX8 ^ lY8);
                if (lX4 | lY4) {
                    if (lResult & 0x40000000) return (lResult ^ 0xC0000000 ^ lX8 ^ lY8);
                    else return (lResult ^ 0x40000000 ^ lX8 ^ lY8);
                } else return (lResult ^ lX8 ^ lY8);
            }
            function F(x,y,z) { return (x & y) | ((~x) & z); }
            function G(x,y,z) { return (x & z) | (y & (~z)); }
            function H(x,y,z) { return (x ^ y ^ z); }
            function I(x,y,z) { return (y ^ (x | (~z))); }
            function FF(a,b,c,d,x,s,ac) {
                a = AddUnsigned(a, AddUnsigned(AddUnsigned(F(b,c,d), x), ac));
                return AddUnsigned(RotateLeft(a, s), b);
            }
            function GG(a,b,c,d,x,s,ac) {
                a = AddUnsigned(a, AddUnsigned(AddUnsigned(G(b,c,d), x), ac));
                return AddUnsigned(RotateLeft(a, s), b);
            }
            function HH(a,b,c,d,x,s,ac) {
                a = AddUnsigned(a, AddUnsigned(AddUnsigned(H(b,c,d), x), ac));
                return AddUnsigned(RotateLeft(a, s), b);
            }
            function II(a,b,c,d,x,s,ac) {
                a = AddUnsigned(a, AddUnsigned(AddUnsigned(I(b,c,d), x), ac));
                return AddUnsigned(RotateLeft(a, s), b);
            }
            function ConvertToWordArray(string) {
                var lWordCount;
                var lMessageLength = string.length;
                var lNumberOfWords_temp1=lMessageLength + 8;
                var lNumberOfWords_temp2=(lNumberOfWords_temp1 - (lNumberOfWords_temp1 % 64)) / 64;
                var lNumberOfWords = (lNumberOfWords_temp2 + 1) * 16;
                var lWordArray=Array(lNumberOfWords-1);
                var lBytePosition = 0;
                var lByteCount = 0;
                while ( lByteCount < lMessageLength ) {
                    lWordCount = (lByteCount - (lByteCount % 4)) / 4;
                    lBytePosition = (lByteCount % 4) * 8;
                    lWordArray[lWordCount] = (lWordArray[lWordCount] | (string.charCodeAt(lByteCount)<<lBytePosition));
                    lByteCount++;
                }
                lWordCount = (lByteCount - (lByteCount % 4)) / 4;
                lBytePosition = (lByteCount % 4) * 8;
                lWordArray[lWordCount] = lWordArray[lWordCount] | (0x80<<lBytePosition);
                lWordArray[lNumberOfWords-2] = lMessageLength << 3;
                lWordArray[lNumberOfWords-1] = lMessageLength >>> 29;
                return lWordArray;
            }
            function WordToHex(lValue) {
                var WordToHexValue="",WordToHexValue_temp="",lByte,lCount;
                for (lCount = 0;lCount<=3;lCount++) {
                    lByte = (lValue >>> (lCount*8)) & 255;
                    WordToHexValue_temp = "0" + lByte.toString(16);
                    WordToHexValue = WordToHexValue + WordToHexValue_temp.substr(WordToHexValue_temp.length-2,2);
                }
                return WordToHexValue;
            }
            function Utf8Encode(string) {
                string = string.replace(/\r\n/g,"\n");
                var utftext = "";
                for (var n = 0; n < string.length; n++) {
                    var c = string.charCodeAt(n);
                    if (c < 128) {
                        utftext += String.fromCharCode(c);
                    }
                    else if((c > 127) && (c < 2048)) {
                        utftext += String.fromCharCode((c >> 6) | 192);
                        utftext += String.fromCharCode((c & 63) | 128);
                    }
                    else {
                        utftext += String.fromCharCode((c >> 12) | 224);
                        utftext += String.fromCharCode(((c >> 6) & 63) | 128);
                        utftext += String.fromCharCode((c & 63) | 128);
                    }
                }
                return utftext;
            }
            var x=Array();
            var k,AA,BB,CC,DD,a,b,c,d;
            var S11=7, S12=12, S13=17, S14=22;
            var S21=5, S22=9 , S23=14, S24=20;
            var S31=4, S32=11, S33=16, S34=23;
            var S41=6, S42=10, S43=15, S44=21;
            string = Utf8Encode(string);
            x = ConvertToWordArray(string);
            a = 0x67452301; b = 0xEFCDAB89; c = 0x98BADCFE; d = 0x10325476;
            for (k=0;k<x.length;k+=16) {
                AA=a; BB=b; CC=c; DD=d;
                a=FF(a,b,c,d,x[k+0], S11,0xD76AA478);
                d=FF(d,a,b,c,x[k+1], S12,0xE8C7B756);
                c=FF(c,d,a,b,x[k+2], S13,0x242070DB);
                b=FF(b,c,d,a,x[k+3], S14,0xC1BDCEEE);
                a=FF(a,b,c,d,x[k+4], S11,0xF57C0FAF);
                d=FF(d,a,b,c,x[k+5], S12,0x4787C62A);
                c=FF(c,d,a,b,x[k+6], S13,0xA8304613);
                b=FF(b,c,d,a,x[k+7], S14,0xFD469501);
                a=FF(a,b,c,d,x[k+8], S11,0x698098D8);
                d=FF(d,a,b,c,x[k+9], S12,0x8B44F7AF);
                c=FF(c,d,a,b,x[k+10],S13,0xFFFF5BB1);
                b=FF(b,c,d,a,x[k+11],S14,0x895CD7BE);
                a=FF(a,b,c,d,x[k+12],S11,0x6B901122);
                d=FF(d,a,b,c,x[k+13],S12,0xFD987193);
                c=FF(c,d,a,b,x[k+14],S13,0xA679438E);
                b=FF(b,c,d,a,x[k+15],S14,0x49B40821);
                a=GG(a,b,c,d,x[k+1], S21,0xF61E2562);
                d=GG(d,a,b,c,x[k+6], S22,0xC040B340);
                c=GG(c,d,a,b,x[k+11],S23,0x265E5A51);
                b=GG(b,c,d,a,x[k+0], S24,0xE9B6C7AA);
                a=GG(a,b,c,d,x[k+5], S21,0xD62F105D);
                d=GG(d,a,b,c,x[k+10],S22,0x2441453);
                c=GG(c,d,a,b,x[k+15],S23,0xD8A1E681);
                b=GG(b,c,d,a,x[k+4], S24,0xE7D3FBC8);
                a=GG(a,b,c,d,x[k+9], S21,0x21E1CDE6);
                d=GG(d,a,b,c,x[k+14],S22,0xC33707D6);
                c=GG(c,d,a,b,x[k+3], S23,0xF4D50D87);
                b=GG(b,c,d,a,x[k+8], S24,0x455A14ED);
                a=GG(a,b,c,d,x[k+13],S21,0xA9E3E905);
                d=GG(d,a,b,c,x[k+2], S22,0xFCEFA3F8);
                c=GG(c,d,a,b,x[k+7], S23,0x676F02D9);
                b=GG(b,c,d,a,x[k+12],S24,0x8D2A4C8A);
                a=HH(a,b,c,d,x[k+5], S31,0xFFFA3942);
                d=HH(d,a,b,c,x[k+8], S32,0x8771F681);
                c=HH(c,d,a,b,x[k+11],S33,0x6D9D6122);
                b=HH(b,c,d,a,x[k+14],S34,0xFDE5380C);
                a=HH(a,b,c,d,x[k+1], S31,0xA4BEEA44);
                d=HH(d,a,b,c,x[k+4], S32,0x4BDECFA9);
                c=HH(c,d,a,b,x[k+7], S33,0xF6BB4B60);
                b=HH(b,c,d,a,x[k+10],S34,0xBEBFBC70);
                a=HH(a,b,c,d,x[k+13],S31,0x289B7EC6);
                d=HH(d,a,b,c,x[k+0], S32,0xEAA127FA);
                c=HH(c,d,a,b,x[k+3], S33,0xD4EF3085);
                b=HH(b,c,d,a,x[k+6], S34,0x4881D05);
                a=HH(a,b,c,d,x[k+9], S31,0xD9D4D039);
                d=HH(d,a,b,c,x[k+12],S32,0xE6DB99E5);
                c=HH(c,d,a,b,x[k+15],S33,0x1FA27CF8);
                b=HH(b,c,d,a,x[k+2], S34,0xC4AC5665);
                a=II(a,b,c,d,x[k+0], S41,0xF4292244);
                d=II(d,a,b,c,x[k+7], S42,0x432AFF97);
                c=II(c,d,a,b,x[k+14],S43,0xAB9423A7);
                b=II(b,c,d,a,x[k+5], S44,0xFC93A039);
                a=II(a,b,c,d,x[k+12],S41,0x655B59C3);
                d=II(d,a,b,c,x[k+3], S42,0x8F0CCC92);
                c=II(c,d,a,b,x[k+10],S43,0xFFEFF47D);
                b=II(b,c,d,a,x[k+1], S44,0x85845DD1);
                a=II(a,b,c,d,x[k+8], S41,0x6FA87E4F);
                d=II(d,a,b,c,x[k+15],S42,0xFE2CE6E0);
                c=II(c,d,a,b,x[k+6], S43,0xA3014314);
                b=II(b,c,d,a,x[k+13],S44,0x4E0811A1);
                a=II(a,b,c,d,x[k+4], S41,0xF7537E82);
                d=II(d,a,b,c,x[k+11],S42,0xBD3AF235);
                c=II(c,d,a,b,x[k+2], S43,0x2AD7D2BB);
                b=II(b,c,d,a,x[k+9], S44,0xEB86D391);
                a=AddUnsigned(a,AA);
                b=AddUnsigned(b,BB);
                c=AddUnsigned(c,CC);
                d=AddUnsigned(d,DD);
            }
            var temp = WordToHex(a)+WordToHex(b)+WordToHex(c)+WordToHex(d);
            return temp.toLowerCase();
        }

        // HTML escaping to prevent XSS
        function escapeHtml(text) {
            if (typeof text !== 'string') return text;
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Initialize App
        window.addEventListener('DOMContentLoaded', () => {
            if (APP_CONFIG.isConfigured) {
                // Initialize View Router & load rulebook default page
                document.getElementById('app').style.display = 'flex';
                
                // Read anchor hash from window URL or default
                const view = window.location.hash.substring(1) || 'rules';
                navigateSPA(view);
            }
        });
    </script>
</body>
</html>
