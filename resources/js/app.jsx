import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  CircleAlert,
  Database,
  Eye,
  Globe2,
  HardDrive,
  KeyRound,
  Loader2,
  Lock,
  LogOut,
  Pencil,
  Play,
  Plus,
  RefreshCw,
  Save,
  Server,
  Settings2,
  ShieldAlert,
  ShieldCheck,
  Terminal,
  Trash2,
  Users,
} from 'lucide-react';
import '../css/app.css';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
      ...(options.headers || {}),
    },
    ...options,
    body: options.body ? JSON.stringify(options.body) : undefined,
  });

  const text = await response.text();
  let payload = {};
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = {
        message: text
          .replace(/<style[\s\S]*?<\/style>/gi, ' ')
          .replace(/<script[\s\S]*?<\/script>/gi, ' ')
          .replace(/<[^>]+>/g, ' ')
          .replace(/\s+/g, ' ')
          .trim()
          .slice(0, 500) || 'Server Error',
      };
    }
  }

  if (!response.ok) {
    const message = payload.message || Object.values(payload.errors || {}).flat().join(' ') || `HTTP ${response.status}`;
    const error = new Error(message);
    error.status = response.status;
    error.payload = payload;
    throw error;
  }

  return payload;
}

function websiteHomeUrl(site) {
  const usageEndpoint = (site?.usage_endpoint_url || '').trim();
  if (usageEndpoint) {
    try {
      const parsed = new URL(usageEndpoint);
      return `${parsed.protocol}//${parsed.host}`;
    } catch {
      // Fall back to the domain-based URL below.
    }
  }

  const domain = (site?.domain || '').trim();
  return domain ? `https://${domain}` : '#';
}

function normalizeWebsiteDomain(domain) {
  return (domain || '')
    .trim()
    .replace(/^https?:\/\//i, '')
    .replace(/\/.*$/, '');
}

function defaultUsageEndpoint(domain) {
  const normalized = normalizeWebsiteDomain(domain);

  return normalized ? `https://${normalized}/application/site-usage/json` : '';
}

function defaultConfigEndpoint(domain) {
  const normalized = normalizeWebsiteDomain(domain);

  return normalized ? `https://${normalized}/application/site-usage/quota/config` : '';
}

function websiteHasEffectiveApiKey(site, setup) {
  return Boolean(site?.has_api_key || setup?.has_default_api_key);
}

function websiteCredentialSummary(site, setup) {
  return site?.uses_default_credentials ? 'Mặc định' : (site?.website_username || 'Riêng');
}

function websitePasswordSummary(site, setup) {
  if (site?.uses_default_credentials) {
    return setup?.has_default_website_password ? 'Đã lưu' : 'Thiếu';
  }

  return site?.has_website_password ? 'Đã lưu' : 'Thiếu';
}

function websiteApiKeySummary(site, setup) {
  if (site?.has_api_key) {
    return 'Riêng';
  }

  if (setup?.has_default_api_key) {
    return 'Mặc định';
  }

  return 'Thiếu';
}

function websiteStorageLimitLabel(site) {
  return site?.quota_bytes > 0 ? site.quota_human : 'Không giới hạn';
}

function websiteUserLimitLabel(site) {
  return site?.user_limit > 0 ? String(site.user_limit) : 'Không giới hạn';
}

function formatDateTime(value) {
  return value ? new Date(value).toLocaleString('vi-VN') : '';
}

function formatCompactDateTime(value) {
  if (!value) {
    return '';
  }

  return new Date(value).toLocaleString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
  });
}

function InfiniteMark() {
  return <span className="infinity-mark" aria-label="Không giới hạn" title="Không giới hạn">∞</span>;
}

function LimitValue({ value }) {
  return value ? value : <InfiniteMark />;
}

function HoverDetail({ primary, secondary, tooltip, align = 'left', titleText = '' }) {
  return (
    <div className="hover-detail" title={titleText}>
      <div className="hover-detail-primary">{primary}</div>
      {secondary ? <small className="hover-detail-secondary">{secondary}</small> : null}
      {tooltip ? <div className={`cell-tooltip ${align === 'right' ? 'align-right' : ''}`}>{tooltip}</div> : null}
    </div>
  );
}

const maintenanceOperations = {
  'clear-cache': {
    route: 'clear-cache',
    label: 'Clear cache',
    runningLabel: 'Đang clear cache',
    doneLabel: 'Đã clear cache',
  },
  'run-update': {
    route: 'run-update',
    label: 'Chạy update.php',
    runningLabel: 'Đang chạy update.php',
    doneLabel: 'Đã chạy update.php',
  },
};

function patchRunStepState(run, stepKey, status = 'running') {
  if (!run || !Array.isArray(run.steps)) {
    return run;
  }

  return {
    ...run,
    status: status === 'running' ? 'running' : run.status,
    current_step: stepKey,
    steps: run.steps.map((step) => {
      if (step.key === stepKey) {
        return { ...step, status };
      }

      if (status === 'running' && step.status === 'running') {
        return { ...step, status: 'pending' };
      }

      return step;
    }),
  };
}

function App() {
  const [user, setUser] = useState(null);
  const [setupStatus, setSetupStatus] = useState(null);
  const [setupOpen, setSetupOpen] = useState(false);
  const [loading, setLoading] = useState(true);

  async function loadSetupStatus() {
    const payload = await api('/api/setup/status');
    setSetupStatus(payload);
    return payload;
  }

  async function loadSession() {
    setLoading(true);

    try {
      const payload = await api('/api/me');
      const currentUser = payload.user || null;
      setUser(currentUser);

      if (currentUser) {
        await loadSetupStatus();
      } else {
        setSetupStatus(null);
      }
    } catch {
      setUser(null);
      setSetupStatus(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadSession();
  }, []);

  async function handleLogin(nextUser) {
    setUser(nextUser);
    setLoading(true);
    try {
      await loadSetupStatus();
    } finally {
      setLoading(false);
    }
  }

  async function handleLogout() {
    await api('/api/logout', { method: 'POST' });
    setUser(null);
    setSetupStatus(null);
    setSetupOpen(false);
  }

  if (loading) {
    return <FullPageLoader />;
  }

  if (!user) {
    return <LoginScreen onLogin={handleLogin} />;
  }

  if (!setupStatus) {
    return <FullPageLoader label="Đang kiểm tra trạng thái setup..." />;
  }

  if (setupOpen || !setupStatus.completed) {
    return (
      <SetupWizard
        user={user}
        initialStatus={setupStatus}
        canCancel={setupStatus.completed}
        onCancel={() => setSetupOpen(false)}
        onLogout={handleLogout}
        onCompleted={async () => {
          await loadSetupStatus();
          setSetupOpen(false);
        }}
      />
    );
  }

  return (
    <Dashboard
      user={user}
      setup={setupStatus.config}
      onLogout={handleLogout}
      onOpenSetup={() => setSetupOpen(true)}
    />
  );
}

function FullPageLoader({ label = 'Đang nạp bảng điều khiển...' }) {
  return (
    <div className="center-screen">
      <Loader2 className="spin" size={34} />
      <p>{label}</p>
    </div>
  );
}

function SectionLoader({ label = 'Đang tải dữ liệu...' }) {
  return (
    <div className="section-loader">
      <Loader2 className="spin" size={24} />
      <p>{label}</p>
    </div>
  );
}

function IconActionButton({
  title,
  icon,
  onClick,
  disabled = false,
  busy = false,
  tone = 'default',
  className = '',
}) {
  return (
    <button
      type="button"
      className={`icon-action-button tone-${tone} ${className}`.trim()}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={title}
    >
      {busy ? <Loader2 className="spin" size={16} /> : icon}
    </button>
  );
}

function LoginScreen({ onLogin }) {
  const [account, setAccount] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');

    const form = event.currentTarget;
    const formData = new FormData(form);
    const accountValue = String(formData.get('account') ?? account).trim();
    const passwordValue = String(formData.get('password') ?? password);

    try {
      const payload = await api('/api/login', {
        method: 'POST',
        body: { account: accountValue, password: passwordValue },
      });
      await onLogin(payload.user);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="login-shell">
      <section className="login-card">
        <div className="login-brand">
          <div className="brand-mark"><ShieldCheck size={18} /></div>
          <div>
            <strong>Winmap Admin</strong>
            <small>Quản trị dung lượng website</small>
          </div>
        </div>
        <div className="login-copy">
          <h1>Đăng nhập</h1>
          <p>Vui lòng điền đầy đủ thông tin để tiếp tục.</p>
        </div>
        <form onSubmit={submit} className="login-form">
          <label>
            Tài khoản administrator
            <input
              name="account"
              value={account}
              onChange={(event) => setAccount(event.target.value)}
              type="text"
              autoComplete="username"
              placeholder="administrator"
              required
            />
          </label>
          <label>
            Mật khẩu
            <input
              name="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              type="password"
              autoComplete="current-password"
              placeholder="Nhập mật khẩu"
              required
            />
          </label>
          {error && <div className="error-box">{error}</div>}
          <button type="submit" disabled={busy} className="primary-button full">
            {busy ? <Loader2 className="spin" size={18} /> : <Lock size={18} />}
            Đăng nhập
          </button>
        </form>
        <p className="login-footnote">Dùng tài khoản administrator đang quản trị website Winmap của bạn.</p>
      </section>
    </main>
  );
}

function SetupWizard({ user, initialStatus, canCancel, onCancel, onLogout, onCompleted }) {
  const initialConfig = initialStatus?.config || {};
  const initialWebsites = Array.isArray(initialStatus?.websites) ? initialStatus.websites.map(normalizeSite) : [];

  const [step, setStep] = useState(initialWebsites.length > 0 ? 2 : 1);
  const [serverForm, setServerForm] = useState({
    server_host: initialConfig.server_host || '',
    server_port: initialConfig.server_port || 22,
    server_username: initialConfig.server_username || '',
    server_password: '',
    drupal_project_path: initialConfig.drupal_project_path || '',
    drupal_site_scheme: initialConfig.drupal_site_scheme || 'https',
  });
  const [authSiteDomain, setAuthSiteDomain] = useState(initialConfig.auth_site_domain || initialWebsites[0]?.domain || '');
  const [defaultAccount, setDefaultAccount] = useState(initialConfig.default_website_username || '');
  const [defaultPassword, setDefaultPassword] = useState('');
  const [defaultApiKey, setDefaultApiKey] = useState('');
  const [sites, setSites] = useState(initialWebsites);
  const [serverPreview, setServerPreview] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busyDiscover, setBusyDiscover] = useState(false);
  const [busySave, setBusySave] = useState(false);

  useEffect(() => {
    if (!authSiteDomain && sites.length > 0) {
      setAuthSiteDomain(sites[0].domain);
    }
  }, [authSiteDomain, sites]);

  function updateServer(key, value) {
    setServerForm((current) => ({ ...current, [key]: value }));
  }

  function updateSite(index, key, value) {
    setSites((current) => current.map((site, siteIndex) => {
      if (siteIndex !== index) return site;

      if (key === 'use_default_credentials') {
        return {
          ...site,
          use_default_credentials: value,
          website_username: value ? '' : (site.website_username || defaultAccount),
          website_password: value ? '' : site.website_password,
          has_website_password: value ? false : site.has_website_password,
        };
      }

      return {
        ...site,
        [key]: value,
        ...(key === 'website_password' && value ? { has_website_password: true } : {}),
      };
    }));
  }

  function applyDefaultsToSites() {
    setSites((current) => current.map((site) => ({
      ...site,
      use_default_credentials: true,
      website_username: '',
      website_password: '',
      has_website_password: false,
    })));
  }

  async function discoverSites(event) {
    event.preventDefault();
    setBusyDiscover(true);
    setError('');
    setNotice('');

    try {
      const payload = await api('/api/setup/discover', { method: 'POST', body: serverForm });
      const discoveredSites = (payload.sites || []).map(normalizeSite);
      if (payload.config) {
        setServerForm((current) => ({
          ...current,
          server_host: payload.config.server_host || current.server_host,
          server_port: payload.config.server_port || current.server_port,
          server_username: payload.config.server_username || current.server_username,
          drupal_project_path: payload.config.drupal_project_path || current.drupal_project_path,
          drupal_site_scheme: payload.config.drupal_site_scheme || current.drupal_site_scheme,
        }));
      }
      if (discoveredSites.length === 0) {
        setServerPreview(payload.server || null);
        setSites([]);
        setError('Không tìm thấy website nào trong project Drupal đã nhập. Kiểm tra lại path dự án và cấu trúc multisite.');
        return;
      }
      setSites(discoveredSites);
      setServerPreview(payload.server || null);
      setStep(2);
      setNotice(`Đã quét được ${payload.sites?.length || 0} website từ project Drupal.`);
      if (!discoveredSites.some((site) => site.domain === authSiteDomain)) {
        setAuthSiteDomain(discoveredSites[0]?.domain || '');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setBusyDiscover(false);
    }
  }

  async function submitSetup(event) {
    event.preventDefault();
    setBusySave(true);
    setError('');
    setNotice('');

    try {
      await api('/api/setup/complete', {
        method: 'POST',
        body: {
          ...serverForm,
          auth_site_domain: authSiteDomain,
          default_website_username: defaultAccount,
          default_website_password: defaultPassword || '',
          default_api_key: defaultApiKey || '',
          websites: sites.map((site) => ({
            name: site.name,
            domain: site.domain,
            usage_endpoint_url: site.usage_endpoint_url,
            config_endpoint_url: site.config_endpoint_url,
            discovery_root: site.discovery_root,
            discovery_conf_path: site.discovery_conf_path,
            credential_override: !site.use_default_credentials,
            website_username: site.use_default_credentials ? '' : site.website_username,
            website_password: site.use_default_credentials ? '' : (site.website_password || ''),
            enabled: site.enabled,
            warning_threshold_percent: site.warning_threshold_percent || 85,
            quota_bytes: site.quota_bytes || 0,
            user_limit: Number(site.user_limit || 0),
          })),
        },
      });

      await onCompleted();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusySave(false);
    }
  }

  return (
    <main className="wizard-shell">
      <header className="topbar wizard-topbar">
        <div>
          <h1>Thiết lập hệ thống</h1>
          <p className="topbar-sub">Kết nối server Drupal, quét multisite và lưu credential administrator.</p>
        </div>
        <div className="topbar-actions">
          <span className="user-chip"><ShieldCheck size={16} />{user.name}</span>
          {canCancel ? (
            <button className="ghost-button" onClick={onCancel}><ArrowLeft size={16} />Quay lại dashboard</button>
          ) : null}
          <button className="ghost-button" onClick={onLogout}><LogOut size={16} />Đăng xuất</button>
        </div>
      </header>

      {error && <div className="error-box wide">{error}</div>}
      {notice && <div className="success-box wide">{notice}</div>}

      <section className="wizard-layout">
        <aside className="wizard-sidebar panel">
          <div className="wizard-step-list">
            <div className={`wizard-step ${step === 1 ? 'active' : ''}`}>
              <div className="wizard-step-icon">1</div>
              <div>
                <strong>Kết nối server</strong>
                <small>IP, port, SSH user, password và path project Drupal 7 multisite.</small>
              </div>
            </div>
            <div className={`wizard-step ${step === 2 ? 'active' : ''}`}>
              <div className="wizard-step-icon">2</div>
              <div>
                <strong>Website và credential</strong>
                <small>Chọn website xác thực admin và điền tài khoản, mật khẩu cho từng site.</small>
              </div>
            </div>
          </div>

          <div className="wizard-note">
            <CircleAlert size={18} />
            <p>Setup này sẽ được dùng chung cho quét multisite, số liệu server, terminal từ xa và xác thực administrator Drupal sau này.</p>
          </div>

          {serverPreview ? (
            <div className="wizard-server-preview">
              <span className="small-label">Server preview</span>
              <strong>{serverPreview.used_human} / {serverPreview.total_human}</strong>
              <small>{serverPreview.remote_host} · mount {serverPreview.path}</small>
              <UsageBar percent={serverPreview.used_percent || 0} />
            </div>
          ) : null}
        </aside>

        <section className="wizard-body panel">
          {step === 1 ? (
            <form className="drawer-form" onSubmit={discoverSites}>
              <div className="panel-header compact">
                <div>
                  <h2>Bước 1: Kết nối tới server Drupal</h2>
                  <p>Backend sẽ dùng SSH để đọc cấu trúc `sites/*/settings.php` và lấy danh sách subdomain trong project.</p>
                </div>
              </div>

              <div className="form-grid two-up">
                <label>Server IP / host<input value={serverForm.server_host} onChange={(event) => updateServer('server_host', event.target.value)} placeholder="103.x.x.x" required /></label>
                <label>SSH port<input value={serverForm.server_port} onChange={(event) => updateServer('server_port', event.target.value)} type="number" min="1" max="65535" required /></label>
                <label>SSH user<input value={serverForm.server_username} onChange={(event) => updateServer('server_username', event.target.value)} placeholder="root hoặc deploy" required /></label>
                <label>Mật khẩu SSH<input value={serverForm.server_password} onChange={(event) => updateServer('server_password', event.target.value)} type="password" placeholder={initialConfig.has_server_password ? 'Để trống để giữ mật khẩu đã lưu' : 'Mật khẩu SSH'} /></label>
              </div>

              <div className="form-grid two-up">
                <label>Path dự án Drupal<input value={serverForm.drupal_project_path} onChange={(event) => updateServer('drupal_project_path', event.target.value)} placeholder="/home/user/public_html/project" required /></label>
                <label>Scheme website
                  <select value={serverForm.drupal_site_scheme} onChange={(event) => updateServer('drupal_site_scheme', event.target.value)}>
                    <option value="https">https</option>
                    <option value="http">http</option>
                  </select>
                </label>
              </div>

              <div className="wizard-actions">
                <button className="primary-button" disabled={busyDiscover}>
                  {busyDiscover ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
                  Quét multisite
                </button>
              </div>
            </form>
          ) : (
            <form className="drawer-form" onSubmit={submitSetup}>
              <div className="panel-header compact">
                <div>
                  <h2>Bước 2: Chọn website và điền credential</h2>
                  <p>Nhập một credential mặc định dùng chung cho toàn bộ website. Chỉ những site nào khác credential chung mới cần bật override và nhập riêng.</p>
                </div>
                <button type="button" className="ghost-button" onClick={() => setStep(1)}><ArrowLeft size={16} />Sửa server</button>
              </div>

              <div className="form-grid two-up">
                <label>Website dùng để xác thực administrator
                  <select value={authSiteDomain} onChange={(event) => setAuthSiteDomain(event.target.value)} required>
                    {sites.map((site) => <option key={site.domain} value={site.domain}>{site.domain}</option>)}
                  </select>
                </label>
                <label>Tổng website đã quét<input value={sites.length} readOnly /></label>
              </div>

              <section className="site-defaults">
                <div>
                  <strong>Credential và API key mặc định</strong>
                  <p>Nếu toàn bộ site dùng chung tài khoản quản trị và cùng một `winmap_site_usage_api_key`, chỉ cần nhập một lần ở đây.</p>
                </div>
                <div className="form-grid two-up">
                  <label>Tài khoản mặc định<input value={defaultAccount} onChange={(event) => setDefaultAccount(event.target.value)} placeholder="administrator" /></label>
                  <label>Mật khẩu mặc định<input value={defaultPassword} onChange={(event) => setDefaultPassword(event.target.value)} type="password" placeholder={initialConfig.has_default_website_password ? 'Để trống để giữ mật khẩu mặc định đã lưu' : 'Mật khẩu cho nhiều site'} /></label>
                  <label>API key mặc định<input value={defaultApiKey} onChange={(event) => setDefaultApiKey(event.target.value)} placeholder={initialConfig.has_default_api_key ? 'Để trống để giữ API key mặc định đã lưu' : 'X-Winmap-Site-Usage-Key dùng chung'} /></label>
                  <div className="button-stack">
                    <button type="button" className="ghost-button" onClick={applyDefaultsToSites}><KeyRound size={16} />Cho toàn bộ dùng mặc định</button>
                  </div>
                </div>
              </section>

              <div className="site-list">
                {sites.map((site, index) => (
                  <article className="site-card" key={site.domain}>
                    <div className="site-card-head">
                      <div>
                        <strong>{site.domain}</strong>
                        <small>{site.discovery_conf_path || 'sites/?'} · endpoint quota {site.config_endpoint_url}</small>
                      </div>
                      <span className={`pill ${site.domain === authSiteDomain ? 'ok' : 'idle'}`}>
                        {site.domain === authSiteDomain ? <CheckCircle2 size={13} /> : null}
                        {site.domain === authSiteDomain ? 'Website auth' : 'Website con'}
                      </span>
                    </div>

                    <div className="site-credential-mode">
                      <label className="check-line">
                        <input
                          type="checkbox"
                          checked={site.use_default_credentials}
                          onChange={(event) => updateSite(index, 'use_default_credentials', event.target.checked)}
                        />
                        Dùng credential mặc định
                      </label>
                      <small>
                        {site.use_default_credentials
                          ? `Site này đang kế thừa credential chung${defaultAccount ? ` (${defaultAccount})` : ''}.`
                          : 'Site này đang dùng credential riêng do bạn nhập tay.'}
                      </small>
                    </div>

                    {site.use_default_credentials ? (
                      <div className="site-credential-inherit">
                        <small>
                          {initialConfig.has_default_website_password || defaultPassword
                            ? 'Mật khẩu sẽ dùng từ credential mặc định đã lưu ở trên.'
                            : 'Chưa có mật khẩu mặc định được lưu. Cần nhập ở khối credential mặc định trước khi hoàn tất setup.'}
                        </small>
                      </div>
                    ) : (
                      <div className="form-grid three-up">
                        <label>Tài khoản website<input value={site.website_username} onChange={(event) => updateSite(index, 'website_username', event.target.value)} placeholder={defaultAccount || 'administrator'} required={!site.use_default_credentials} /></label>
                        <label>Mật khẩu website<input value={site.website_password} onChange={(event) => updateSite(index, 'website_password', event.target.value)} type="password" placeholder={site.has_website_password ? 'Để trống để giữ mật khẩu override đã lưu' : 'Mật khẩu riêng của site'} /></label>
                        <label>Ngưỡng cảnh báo %<input value={site.warning_threshold_percent} onChange={(event) => updateSite(index, 'warning_threshold_percent', event.target.value)} type="number" min="1" max="100" /></label>
                      </div>
                    )}

                    <div className="form-grid two-up">
                      {site.use_default_credentials ? (
                        <label>Ngưỡng cảnh báo %<input value={site.warning_threshold_percent} onChange={(event) => updateSite(index, 'warning_threshold_percent', event.target.value)} type="number" min="1" max="100" /></label>
                      ) : null}
                      <label>Số user được phép<input value={site.user_limit} onChange={(event) => updateSite(index, 'user_limit', event.target.value)} type="number" min="0" step="1" placeholder="0 = không giới hạn" /></label>
                      <label>Dung lượng được phép (GB)<input value={site.quota_gb ?? (site.quota_bytes ? Math.round((site.quota_bytes / 1024 / 1024 / 1024) * 100) / 100 : '')} onChange={(event) => {
                        const value = event.target.value;
                        updateSite(index, 'quota_gb', value);
                        updateSite(index, 'quota_bytes', value === '' ? 0 : Math.round(Number(value) * 1024 * 1024 * 1024));
                      }} type="number" min="0" step="0.01" placeholder="0 = không giới hạn" /></label>
                    </div>

                    {site.use_default_credentials ? (
                      <div className="site-credential-inherit">
                        <small>Hai thông số gói ở trên sẽ đồng bộ xuống `/api/admin/package-config` và quota endpoint của từng website.</small>
                      </div>
                    ) : null}

                    <div className="site-card-foot">
                      <label className="check-line"><input type="checkbox" checked={site.enabled} onChange={(event) => updateSite(index, 'enabled', event.target.checked)} />Theo dõi website này</label>
                      <small>
                        {site.use_default_credentials
                          ? ((initialConfig.has_default_website_password || defaultPassword) ? 'Đang dùng mật khẩu mặc định của hệ thống.' : 'Chưa có mật khẩu mặc định lưu trong hệ thống.')
                          : (site.has_website_password ? 'Đã có mật khẩu override lưu trong hệ thống.' : 'Chưa có mật khẩu override lưu cho website này.')}
                      </small>
                    </div>
                  </article>
                ))}
              </div>

              <div className="wizard-actions split">
                <button type="button" className="ghost-button" onClick={discoverSites} disabled={busyDiscover}>
                  {busyDiscover ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
                  Quét lại multisite
                </button>
                <button className="primary-button" disabled={busySave}>
                  {busySave ? <Loader2 className="spin" size={16} /> : <Save size={16} />}
                  Hoàn tất setup
                </button>
              </div>
            </form>
          )}
        </section>
      </section>
    </main>
  );
}

function normalizeSite(site) {
  return {
    name: site.name || site.domain,
    domain: site.domain || '',
    usage_endpoint_url: site.usage_endpoint_url || '',
    config_endpoint_url: site.config_endpoint_url || '',
    discovery_root: site.discovery_root || '',
    discovery_conf_path: site.discovery_conf_path || '',
    website_username: site.website_username || '',
    website_password: '',
    has_website_password: Boolean(site.has_website_password),
    use_default_credentials: site.uses_default_credentials ?? !(site.website_username || site.has_website_password),
    enabled: site.enabled ?? true,
    warning_threshold_percent: site.warning_threshold_percent || 85,
    quota_bytes: site.quota_bytes || 0,
    quota_gb: site.quota_bytes ? Math.round((site.quota_bytes / 1024 / 1024 / 1024) * 100) / 100 : '',
    user_limit: site.user_limit || 0,
  };
}

function Dashboard({ user, setup, onLogout, onOpenSetup }) {
  const [dashboard, setDashboard] = useState(null);
  const [activeTab, setActiveTab] = useState('websites');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState(null);
  const [provisioningOpen, setProvisioningOpen] = useState(false);
  const [deletingWebsite, setDeletingWebsite] = useState(null);
  const [refreshingId, setRefreshingId] = useState(null);
  const [discovering, setDiscovering] = useState(false);
  const [bulkRefreshing, setBulkRefreshing] = useState(false);
  const [websiteTableBusy, setWebsiteTableBusy] = useState('');
  const [websiteOperationKey, setWebsiteOperationKey] = useState('');
  const [maintenanceBatch, setMaintenanceBatch] = useState({
    running: false,
    operation: '',
    currentIndex: 0,
    total: 0,
    success: 0,
    errors: 0,
    currentSite: '',
    results: [],
  });

  const websites = dashboard?.websites || [];
  const server = dashboard?.server;
  const summary = dashboard?.summary;

  async function loadDashboard({ soft = false } = {}) {
    if (!soft || !dashboard) {
      setLoading(true);
    }
    setError('');
    try {
      setDashboard(await api('/api/dashboard'));
    } catch (err) {
      if (err.status === 428) {
        onOpenSetup();
        return;
      }
      setError(err.message);
    } finally {
      if (!soft || !dashboard) {
        setLoading(false);
      }
    }
  }

  useEffect(() => {
    loadDashboard();
  }, []);

  async function refreshWebsite(id) {
    setRefreshingId(id);
    setError('');
    setNotice('');
    try {
      await api(`/api/websites/${id}/refresh`, { method: 'POST' });
      await loadDashboard({ soft: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setRefreshingId(null);
    }
  }

  async function refreshAllWebsites(silent = false) {
    setBulkRefreshing(true);
    setWebsiteTableBusy('Đang lấy số liệu cho toàn bộ bảng website...');
    setError('');
    if (!silent) {
      setNotice('');
    }

    try {
      const payload = await api('/api/websites/refresh-all', { method: 'POST' });
      if (!silent) {
        setNotice(payload.message || 'Đã lấy số liệu toàn bộ website.');
      }
      await loadDashboard({ soft: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setBulkRefreshing(false);
      setWebsiteTableBusy('');
    }
  }

  async function runWebsiteOperation(site, operation) {
    const config = maintenanceOperations[operation];
    if (!config) {
      return;
    }
    if (!websiteHasEffectiveApiKey(site, setup)) {
      setError(`Website ${site.domain} chưa có API key hiệu lực để chạy thao tác này.`);
      return;
    }

    const key = `${site.id}:${operation}`;
    setWebsiteOperationKey(key);
    setError('');
    setNotice('');

    try {
      const payload = await api(`/api/websites/${site.id}/${config.route}`, { method: 'POST' });
      setNotice(`${config.doneLabel} cho ${site.domain}: ${payload.remote?.message || 'thành công'}.`);
      await loadDashboard({ soft: true });
    } catch (err) {
      setError(`${site.domain}: ${err.message}`);
    } finally {
      setWebsiteOperationKey('');
    }
  }

  async function runMaintenanceBatch(operation) {
    const config = maintenanceOperations[operation];
    const targets = websites.filter((site) => site.enabled && websiteHasEffectiveApiKey(site, setup));
    const skipped = websites.filter((site) => site.enabled && !websiteHasEffectiveApiKey(site, setup)).length;

    if (!config || maintenanceBatch.running) {
      return;
    }
    if (targets.length === 0) {
      setError('Không có website nào đủ điều kiện chạy bảo trì. Cần bật website và có API key riêng hoặc API key mặc định trong setup.');
      return;
    }

    let success = 0;
    let errors = 0;
    setError('');
    setNotice('');
    setMaintenanceBatch({
      running: true,
      operation,
      currentIndex: 0,
      total: targets.length,
      success: 0,
      errors: 0,
      currentSite: '',
      results: [],
    });

    for (let index = 0; index < targets.length; index += 1) {
      const site = targets[index];
      setMaintenanceBatch((current) => ({
        ...current,
        currentIndex: index,
        currentSite: site.domain,
        results: [
          { id: site.id, domain: site.domain, status: 'running', message: config.runningLabel },
          ...current.results.filter((item) => item.id !== site.id),
        ].slice(0, 12),
      }));

      let result;
      try {
        const payload = await api(`/api/websites/${site.id}/${config.route}`, { method: 'POST' });
        success += 1;
        result = {
          id: site.id,
          domain: site.domain,
          status: 'success',
          message: payload.remote?.message || config.doneLabel,
        };
      } catch (err) {
        errors += 1;
        result = {
          id: site.id,
          domain: site.domain,
          status: 'error',
          message: err.message,
        };
      }

      setMaintenanceBatch((current) => ({
        ...current,
        currentIndex: index + 1,
        currentSite: '',
        success,
        errors,
        results: [
          result,
          ...current.results.filter((item) => item.id !== site.id),
        ].slice(0, 12),
      }));
    }

    setMaintenanceBatch((current) => ({
      ...current,
      running: false,
      currentSite: '',
      currentIndex: targets.length,
      success,
      errors,
    }));
    setNotice(`${config.doneLabel} ${targets.length} website: ${success} thành công, ${errors} lỗi${skipped ? `, bỏ qua ${skipped} site thiếu API key hiệu lực` : ''}.`);
    await loadDashboard({ soft: true });
  }

  function deleteWebsite(site) {
    setDeletingWebsite(site);
  }

  async function syncDiscoveredWebsites() {
    setDiscovering(true);
    setWebsiteTableBusy('Đang quét multisite và cập nhật lại danh sách website...');
    setError('');
    setNotice('');
    try {
      const payload = await api('/api/websites/discovery/sync', { method: 'POST' });
      setNotice(payload.message || 'Đã quét multisite thành công.');
      await loadDashboard({ soft: true });
    } catch (err) {
      setError(err.message);
    } finally {
      setDiscovering(false);
      setWebsiteTableBusy('');
    }
  }

  return (
    <main className="app-shell">
      <header className="topbar">
        <div className="topbar-actions">
          <button className="ghost-button" onClick={onOpenSetup}><Settings2 size={16} />Cấu hình setup</button>
          <span className="user-chip"><ShieldCheck size={16} />{user.name}</span>
          <button className="ghost-button" onClick={onLogout}><LogOut size={16} />Đăng xuất</button>
        </div>
      </header>

      {error && <div className="error-box wide">{error}</div>}
      {notice && <div className="success-box wide">{notice}</div>}

      {loading ? <FullPageLoader /> : (
        <>
          <section className="metrics-grid">
            <MetricCard icon={<Server />} label="Đã sử dụng" value={server?.used_human || '0 B'} sub={`${server?.used_percent || 0}% của ${server?.total_human || '0 B'}`} tone="blue" percent={server?.used_percent || 0} />
            <MetricCard icon={<HardDrive />} label="Còn trống" value={server?.free_human || '0 B'} sub={`${server?.remote_host || ''} · ${server?.path || '/'}`} tone="green" />
            <MetricCard icon={<Globe2 />} label="Website" value={summary?.website_count || 0} sub={`${summary?.warning_count || 0} sắp đầy · ${summary?.over_quota_count || 0} đã khóa`} tone="amber" />
            <MetricCard icon={<Users />} label="Tài khoản hiện tại" value={summary?.total_user_count || 0} sub="Tổng user hệ thống đã ghi nhận gần nhất" tone="slate" />
            <MetricCard icon={<Database />} label="Tổng project" value={summary?.total_project_human || '0 B'} sub={`Disk ${summary?.total_disk_human || '0 B'} · DB ${summary?.total_database_human || '0 B'}`} tone="slate" />
          </section>

          <section className="dashboard-tabs">
            <button className={`dashboard-tab ${activeTab === 'websites' ? 'active' : ''}`} onClick={() => setActiveTab('websites')}>
              <Globe2 size={16} />
              Website
            </button>
            <button className={`dashboard-tab ${activeTab === 'maintenance' ? 'active' : ''}`} onClick={() => setActiveTab('maintenance')}>
              <Activity size={16} />
              Bảo trì hàng loạt
            </button>
            <button className={`dashboard-tab ${activeTab === 'terminal' ? 'active' : ''}`} onClick={() => setActiveTab('terminal')}>
              <Terminal size={16} />
              Terminal
            </button>
          </section>

          {activeTab === 'websites' && (
            <section className="dashboard-tab-panel">
              <WebsitePanel
                setup={setup}
                websites={websites}
                refreshingId={refreshingId}
                discovering={discovering}
                bulkRefreshing={bulkRefreshing}
                tableBusyMessage={websiteTableBusy}
                websiteOperationKey={websiteOperationKey}
                onRefresh={refreshWebsite}
                onRefreshAll={refreshAllWebsites}
                onRunOperation={runWebsiteOperation}
                onEdit={setEditing}
                onDelete={deleteWebsite}
                onCreate={() => setEditing({})}
                onProvision={() => setProvisioningOpen(true)}
                onDiscoverySync={syncDiscoveredWebsites}
              />
            </section>
          )}

          {activeTab === 'maintenance' && (
            <section className="dashboard-tab-panel single-panel">
              <MaintenanceBatchPanel
                setup={setup}
                websites={websites}
                batch={maintenanceBatch}
                onRun={runMaintenanceBatch}
              />
            </section>
          )}

          {activeTab === 'terminal' && (
            <section className="dashboard-tab-panel single-panel">
              <TerminalPanel onError={setError} projectPath={setup.drupal_project_path} />
            </section>
          )}
        </>
      )}

      {editing !== null && (
        <WebsiteDrawer
          setup={setup}
          website={editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await loadDashboard({ soft: true });
          }}
        />
      )}

      {provisioningOpen && (
        <WebsiteProvisionDrawer
          onClose={() => setProvisioningOpen(false)}
          onCreated={async () => {
            await loadDashboard({ soft: true });
            setNotice('Khởi tạo website hoàn tất và đã thêm vào danh sách giám sát.');
          }}
        />
      )}

      {deletingWebsite && (
        <WebsiteDeletionDrawer
          website={deletingWebsite}
          onClose={() => setDeletingWebsite(null)}
          onDeleted={async () => {
            setDeletingWebsite(null);
            await loadDashboard({ soft: true });
            setNotice(`Đã xóa website ${deletingWebsite.domain} và các tài nguyên liên quan.`);
          }}
        />
      )}
    </main>
  );
}

function MaintenanceBatchPanel({ setup, websites, batch, onRun }) {
  const readyCount = websites.filter((site) => site.enabled && websiteHasEffectiveApiKey(site, setup)).length;
  const missingKeyCount = websites.filter((site) => site.enabled && !websiteHasEffectiveApiKey(site, setup)).length;
  const percent = batch.total > 0 ? Math.round((batch.currentIndex / batch.total) * 100) : 0;
  const activeConfig = maintenanceOperations[batch.operation];

  return (
    <section className="panel maintenance-panel">
      <div className="panel-header compact">
        <div>
          <h2>Bảo trì hàng loạt</h2>
        </div>
        <Activity size={20} />
      </div>

      <div className="maintenance-actions">
        <button className="ghost-button" onClick={() => onRun('clear-cache')} disabled={batch.running || readyCount === 0}>
          {batch.running && batch.operation === 'clear-cache' ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
          Clear cache tất cả
        </button>
        <button className="primary-button" onClick={() => onRun('run-update')} disabled={batch.running || readyCount === 0}>
          {batch.running && batch.operation === 'run-update' ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
          Chạy update.php
        </button>
      </div>

      <div className="batch-summary">
        <span>{readyCount} site sẵn sàng</span>
        <span>{missingKeyCount} thiếu API key</span>
      </div>

      <div className="batch-progress">
        <div className="batch-progress-head">
          <strong>{batch.running ? activeConfig?.runningLabel : 'Sẵn sàng chạy'}</strong>
          <span>{percent}%</span>
        </div>
        <div className="batch-progress-track">
          <span style={{ width: `${percent}%` }} />
        </div>
        <small>
          {batch.running
            ? `${batch.currentIndex}/${batch.total} hoàn tất${batch.currentSite ? ` · đang xử lý ${batch.currentSite}` : ''}`
            : batch.total > 0
              ? `${batch.success} thành công · ${batch.errors} lỗi`
              : 'Chưa có batch nào chạy trong phiên này'}
        </small>
      </div>

      {batch.results.length > 0 && (
        <div className="batch-results">
          {batch.results.map((item) => (
            <div key={`${item.id}-${item.status}`} className={`batch-result ${item.status}`}>
              {item.status === 'running' && <Loader2 className="spin" size={15} />}
              {item.status === 'success' && <CheckCircle2 size={15} />}
              {item.status === 'error' && <CircleAlert size={15} />}
              <span>{item.domain}</span>
              <small>{item.message}</small>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function MetricCard({ icon, label, value, sub, tone, percent }) {
  return (
    <article className={`metric-card tone-${tone}`}>
      <div className="metric-icon">{icon}</div>
      <div>
        <span>{label}</span>
        <strong>{value}</strong>
        <small>{sub}</small>
        {typeof percent === 'number' && <UsageBar percent={percent} />}
      </div>
    </article>
  );
}

function WebsitePanel({
  setup,
  websites,
  refreshingId,
  discovering,
  bulkRefreshing,
  tableBusyMessage,
  websiteOperationKey,
  onRefresh,
  onRefreshAll,
  onRunOperation,
  onEdit,
  onDelete,
  onCreate,
  onProvision,
  onDiscoverySync,
}) {
  const tableLocked = Boolean(tableBusyMessage);
  const headerBusy = bulkRefreshing || discovering;

  return (
    <section className="panel website-panel">
      <div className="panel-header">
        <div>
          <h2>Website đang giám sát</h2>
          <p>Làm việc theo từng dòng cho thao tác riêng lẻ, và chỉ khóa trong phạm vi bảng khi chạy tác vụ toàn danh sách.</p>
        </div>
        <div className="panel-actions">
          <button className="ghost-button" onClick={() => onRefreshAll(false)} disabled={headerBusy}>
            {bulkRefreshing ? <Loader2 className="spin" size={16} /> : <Database size={16} />}
            Lấy số liệu tất cả
          </button>
          <button className="ghost-button" onClick={onDiscoverySync} disabled={headerBusy}>
            {discovering ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
            Quét multisite
          </button>
          <button className="primary-button" onClick={onProvision}><Server size={16} />Tạo website mới</button>
          <button className="ghost-button" onClick={onCreate}><Plus size={17} />Thêm site đã có</button>
        </div>
      </div>

      <div className="website-table-region">
        <div className="website-table-wrap">
          <table className="website-table">
          <thead>
            <tr>
              <th className="sticky-col">Website</th>
              <th>Credential</th>
              <th>Mật khẩu</th>
              <th>API key</th>
              <th>Dung lượng</th>
              <th>Người dùng</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            {websites.length === 0 && (
              <tr>
                <td colSpan="8" className="empty-cell website-empty">Chưa có website nào. Chạy setup hoặc quét multisite để bắt đầu.</td>
              </tr>
            )}

            {websites.map((site) => {
              const statusMessage = site.last_error
                ? `Usage lỗi: ${site.last_error}`
                : (site.last_sync_status === 'error' ? `Sync quota lỗi: ${site.last_sync_error}` : '');
              const lastChecked = formatDateTime(site.last_checked_at);
              const lastSynced = formatDateTime(site.last_synced_at);
              const lastCheckedShort = formatCompactDateTime(site.last_checked_at);
              const hasLimit = site.quota_bytes > 0;
              const hasUserLimit = site.user_limit > 0;

              return (
                <tr
                  key={site.id}
                  className={site.last_is_blocked ? 'danger-row' : (site.last_is_warning ? 'warn-row' : '')}
                >
                  <td className="sticky-col website-sticky-cell">
                    <div className="website-name-block">
                      <strong>
                        <a className="website-link" href={websiteHomeUrl(site)} target="_blank" rel="noreferrer noopener">
                          {site.name}
                        </a>
                      </strong>
                      <small>{site.domain}</small>
                    </div>
                  </td>

                  <td>
                    <HoverDetail
                      primary={websiteCredentialSummary(site, setup)}
                      secondary={site.uses_default_credentials ? 'Dùng chung' : 'Tài khoản riêng'}
                      titleText={site.uses_default_credentials ? `Credential mặc định: ${setup?.default_website_username || 'chưa điền'}` : `Credential riêng: ${site.website_username || 'chưa điền'}`}
                      tooltip={(
                        <div className="cell-tooltip-stack">
                          <div><span>Nguồn</span><strong>{site.uses_default_credentials ? 'Mặc định hệ thống' : 'Website riêng'}</strong></div>
                          <div><span>Tài khoản</span><strong>{site.uses_default_credentials ? (setup?.default_website_username || 'Chưa điền') : (site.website_username || 'Chưa điền')}</strong></div>
                        </div>
                      )}
                    />
                  </td>

                  <td>
                    <HoverDetail
                      primary={websitePasswordSummary(site, setup)}
                      secondary={site.uses_default_credentials ? 'Mặc định' : 'Riêng'}
                      titleText={site.uses_default_credentials ? (setup?.has_default_website_password ? 'Đã lưu mật khẩu mặc định' : 'Chưa có mật khẩu mặc định') : (site?.has_website_password ? 'Đã lưu mật khẩu riêng' : 'Chưa có mật khẩu riêng')}
                      tooltip={(
                        <div className="cell-tooltip-stack">
                          <div><span>Trạng thái</span><strong>{site.uses_default_credentials ? (setup?.has_default_website_password ? 'Đã lưu mật khẩu mặc định' : 'Chưa có mật khẩu mặc định') : (site?.has_website_password ? 'Đã lưu mật khẩu riêng' : 'Chưa có mật khẩu riêng')}</strong></div>
                          <div><span>Loại</span><strong>{site.uses_default_credentials ? 'Mật khẩu dùng chung cho nhóm website' : 'Mật khẩu riêng cho website này'}</strong></div>
                        </div>
                      )}
                    />
                  </td>

                  <td>
                    <HoverDetail
                      primary={websiteApiKeySummary(site, setup)}
                      secondary={websiteHasEffectiveApiKey(site, setup) ? 'Sẵn sàng' : 'Thiếu key'}
                      titleText={websiteApiKeySummary(site, setup)}
                      tooltip={(
                        <div className="cell-tooltip-stack">
                          <div><span>Nguồn</span><strong>{site.has_api_key ? 'API key riêng của website' : (setup?.has_default_api_key ? 'API key mặc định của hệ thống' : 'Chưa cấu hình API key')}</strong></div>
                          <div><span>Khả năng</span><strong>{websiteHasEffectiveApiKey(site, setup) ? 'Dùng được cho quota sync và bảo trì từ xa' : 'Chưa đủ điều kiện quota sync hoặc bảo trì từ xa'}</strong></div>
                        </div>
                      )}
                    />
                  </td>

                  <td>
                    <div className="metric-cell">
                      <HoverDetail
                        primary={(
                          <span className="ratio-inline">
                            <span>{site.last_project_human}</span>
                            <span className="ratio-separator">/</span>
                            <LimitValue value={hasLimit ? site.quota_human : ''} />
                          </span>
                        )}
                        secondary={hasLimit ? `${site.last_usage_percent || 0}% quota` : 'Không giới hạn'}
                        titleText={`Tổng ${site.last_project_human}. Quota ${websiteStorageLimitLabel(site)}. Disk ${site.last_disk_human}. Database ${site.last_database_human}.`}
                        tooltip={(
                          <div className="cell-tooltip-grid">
                            <div><span>Tổng</span><strong>{site.last_project_human}</strong></div>
                            <div><span>Quota</span><strong>{websiteStorageLimitLabel(site)}</strong></div>
                            <div><span>Disk</span><strong>{site.last_disk_human}</strong></div>
                            <div><span>Database</span><strong>{site.last_database_human}</strong></div>
                            <div><span>Cảnh báo</span><strong>{site.warning_threshold_percent}%</strong></div>
                            <div><span>Trạng thái</span><strong>{hasLimit ? `${site.last_usage_percent || 0}% quota` : 'Không giới hạn quota'}</strong></div>
                          </div>
                        )}
                      />
                      {hasLimit ? <UsageBar percent={site.last_usage_percent || 0} /> : null}
                    </div>
                  </td>

                  <td>
                    <HoverDetail
                      primary={(
                        <span className="ratio-inline">
                          <span>{site.last_user_count || 0}</span>
                          <span className="ratio-separator">/</span>
                          <LimitValue value={hasUserLimit ? String(site.user_limit) : ''} />
                        </span>
                      )}
                      secondary={hasUserLimit ? `${site.user_usage_percent || 0}% giới hạn` : 'Không giới hạn'}
                      titleText={`Tài khoản hiện tại ${site.last_user_count || 0}. Giới hạn ${websiteUserLimitLabel(site)}.`}
                      tooltip={(
                        <div className="cell-tooltip-grid">
                          <div><span>Hiện tại</span><strong>{site.last_user_count || 0}</strong></div>
                          <div><span>Giới hạn</span><strong>{websiteUserLimitLabel(site)}</strong></div>
                          <div><span>Trạng thái</span><strong>{hasUserLimit ? `${site.user_usage_percent || 0}% giới hạn` : 'Không giới hạn user'}</strong></div>
                        </div>
                      )}
                    />
                  </td>

                  <td>
                    <HoverDetail
                      primary={<StatusPill site={site} />}
                      secondary={lastCheckedShort || 'Chưa kiểm tra'}
                      align="right"
                      titleText={statusMessage || lastChecked || 'Chưa có trạng thái'}
                      tooltip={(
                        <div className="cell-tooltip-stack">
                          <div><span>Kiểm tra</span><strong>{lastChecked || 'Chưa lấy số liệu'}</strong></div>
                          <div><span>Đồng bộ quota</span><strong>{lastSynced || 'Chưa có lần đồng bộ quota gần nhất'}</strong></div>
                          <div><span>Chi tiết</span><strong>{statusMessage || 'Không có lỗi đồng bộ gần nhất'}</strong></div>
                        </div>
                      )}
                    />
                  </td>

                  <td className="website-actions-cell">
                    <div className="row-icon-actions website-row-actions">
                      <IconActionButton
                        title={websiteHasEffectiveApiKey(site, setup) ? 'Clear cache website này' : 'Website thiếu API key hiệu lực'}
                        icon={<RefreshCw size={16} />}
                        onClick={() => onRunOperation(site, 'clear-cache')}
                        disabled={tableLocked || websiteOperationKey !== '' || !websiteHasEffectiveApiKey(site, setup)}
                        busy={websiteOperationKey === `${site.id}:clear-cache`}
                      />
                      <IconActionButton
                        title={websiteHasEffectiveApiKey(site, setup) ? 'Chạy update.php cho website này' : 'Website thiếu API key hiệu lực'}
                        icon={<Play size={16} />}
                        onClick={() => onRunOperation(site, 'run-update')}
                        disabled={tableLocked || websiteOperationKey !== '' || !websiteHasEffectiveApiKey(site, setup)}
                        busy={websiteOperationKey === `${site.id}:run-update`}
                        tone="primary"
                      />
                      <IconActionButton
                        title="Refresh số liệu website"
                        icon={<Database size={16} />}
                        onClick={() => onRefresh(site.id)}
                        disabled={tableLocked || websiteOperationKey !== '' || refreshingId !== null}
                        busy={refreshingId === site.id}
                      />
                      <IconActionButton
                        title="Sửa website"
                        icon={<Pencil size={16} />}
                        onClick={() => onEdit(site)}
                        disabled={tableLocked || websiteOperationKey !== '' || refreshingId !== null}
                      />
                      <IconActionButton
                        title="Xóa sạch website"
                        icon={<Trash2 size={16} />}
                        onClick={() => onDelete(site)}
                        disabled={tableLocked || websiteOperationKey !== '' || refreshingId !== null}
                        tone="danger"
                      />
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        </div>

        {tableBusyMessage ? (
          <div className="table-overlay">
            <div className="table-overlay-card">
              <Loader2 className="spin" size={18} />
              <span>{tableBusyMessage}</span>
            </div>
          </div>
        ) : null}
      </div>
    </section>
  );
}

function TerminalPanel({ onError, projectPath }) {
  const [command, setCommand] = useState('df -h .');
  const [cwd, setCwd] = useState(projectPath || '');
  const [output, setOutput] = useState('');
  const [busy, setBusy] = useState(false);
  const [history, setHistory] = useState([]);

  useEffect(() => {
    setCwd(projectPath || '');
  }, [projectPath]);

  async function loadHistory() {
    const payload = await api('/api/terminal/history');
    setHistory(payload.data || []);
  }

  useEffect(() => {
    loadHistory().catch(() => {});
  }, []);

  async function run(event) {
    event.preventDefault();
    setBusy(true);
    onError('');
    try {
      const payload = await api('/api/terminal/run', { method: 'POST', body: { command, cwd: cwd || null } });
      setOutput(payload.data.output || '');
      await loadHistory();
    } catch (err) {
      onError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="panel terminal-panel">
      <div className="panel-header">
        <div>
          <h2>Terminal Server</h2>
        </div>
        <Terminal />
      </div>
      <form onSubmit={run} className="terminal-form">
        <input value={cwd} onChange={(event) => setCwd(event.target.value)} placeholder="cwd remote, mặc định = path Drupal" />
        <div className="terminal-command-row">
          <input value={command} onChange={(event) => setCommand(event.target.value)} placeholder="df -h ." />
          <button className="primary-button" disabled={busy}>{busy ? <Loader2 className="spin" size={16} /> : <Play size={16} />}Chạy</button>
        </div>
      </form>
      <pre className="terminal-output">{output || 'Output sẽ hiển thị ở đây.'}</pre>
      <div className="command-history">
        {history.slice(0, 5).map((item) => (
          <button key={item.id} onClick={() => setCommand(item.command)}>
            <span>{item.status}</span>{item.command}
          </button>
        ))}
      </div>
    </section>
  );
}

function WebsiteDrawer({ website, setup, onClose, onSaved }) {
  const isEdit = Boolean(website.id);
  const [form, setForm] = useState({
    name: website.name || '',
    domain: website.domain || '',
    usage_endpoint_url: website.usage_endpoint_url || '',
    config_endpoint_url: website.config_endpoint_url || '',
    api_key: '',
    website_username: website.website_username || '',
    website_password: '',
    quota_gb: website.quota_bytes ? Math.round((website.quota_bytes / 1024 / 1024 / 1024) * 100) / 100 : '',
    user_limit: website.user_limit || 0,
    warning_threshold_percent: website.warning_threshold_percent || 85,
    enabled: website.enabled ?? true,
    sync_now: false,
    notes: website.notes || '',
  });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const endpointHint = useMemo(() => {
    return defaultUsageEndpoint(form.domain);
  }, [form.domain]);

  const configEndpointHint = useMemo(() => {
    return defaultConfigEndpoint(form.domain);
  }, [form.domain]);

  function update(key, value) {
    setForm((current) => {
      if (key !== 'domain') {
        return { ...current, [key]: value };
      }

      const previousUsageEndpoint = defaultUsageEndpoint(current.domain);
      const previousConfigEndpoint = defaultConfigEndpoint(current.domain);
      const nextUsageEndpoint = defaultUsageEndpoint(value);
      const nextConfigEndpoint = defaultConfigEndpoint(value);
      const currentName = (current.name || '').trim();
      const previousDomain = normalizeWebsiteDomain(current.domain);

      return {
        ...current,
        domain: value,
        name: currentName === '' || currentName === previousDomain ? normalizeWebsiteDomain(value) : current.name,
        usage_endpoint_url: current.usage_endpoint_url === '' || current.usage_endpoint_url === previousUsageEndpoint
          ? nextUsageEndpoint
          : current.usage_endpoint_url,
        config_endpoint_url: current.config_endpoint_url === '' || current.config_endpoint_url === previousConfigEndpoint
          ? nextConfigEndpoint
          : current.config_endpoint_url,
      };
    });
  }

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const method = isEdit ? 'PUT' : 'POST';
      const url = isEdit ? `/api/websites/${website.id}` : '/api/websites';
      const body = {
        ...form,
        name: form.name || normalizeWebsiteDomain(form.domain),
        usage_endpoint_url: form.usage_endpoint_url || defaultUsageEndpoint(form.domain),
        config_endpoint_url: form.config_endpoint_url || defaultConfigEndpoint(form.domain),
      };

      await api(url, { method, body });
      onSaved();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="drawer-backdrop">
      <aside className="drawer">
        <div className="panel-header">
          <div>
            <h2>{isEdit ? 'Sửa website' : 'Thêm site đã có'}</h2>
            <p>{isEdit ? 'Cấu hình quota, endpoint đo dung lượng và credential quản trị website.' : 'Chỉ lưu một website đã tồn tại vào danh sách theo dõi, không tạo subdomain/folder/database trên server.'}</p>
          </div>
          <button className="ghost-button" onClick={onClose}>Đóng</button>
        </div>
        <form onSubmit={submit} className="drawer-form">
          <label>Tên website<input value={form.name} onChange={(event) => update('name', event.target.value)} placeholder="Tự dùng domain nếu để trống" /></label>
          <label>Domain<input value={form.domain} onChange={(event) => update('domain', event.target.value)} placeholder="enter.winmap.vn" required /></label>
          <label>Usage endpoint<input value={form.usage_endpoint_url} onChange={(event) => update('usage_endpoint_url', event.target.value)} placeholder={endpointHint || 'Tự sinh từ domain khi lưu'} /></label>
          <label>Quota config endpoint<input value={form.config_endpoint_url} onChange={(event) => update('config_endpoint_url', event.target.value)} placeholder={configEndpointHint || 'https://domain/application/site-usage/quota/config'} /></label>
          <label>API key riêng (tùy chọn)<input value={form.api_key} onChange={(event) => update('api_key', event.target.value)} placeholder={setup?.has_default_api_key ? (isEdit ? 'Bỏ trống để giữ key cũ hoặc dùng key mặc định' : 'Để trống để dùng API key mặc định trong setup') : (isEdit ? 'Bỏ trống để giữ key cũ' : 'X-Winmap-Site-Usage-Key')} /></label>
          <div className="form-grid two-up">
            <label>Tài khoản website<input value={form.website_username} onChange={(event) => update('website_username', event.target.value)} placeholder="administrator" /></label>
            <label>Mật khẩu website<input value={form.website_password} onChange={(event) => update('website_password', event.target.value)} type="password" placeholder={website.has_website_password ? 'Bỏ trống để giữ mật khẩu cũ' : 'Mật khẩu website'} /></label>
          </div>
          <div className="form-grid three-up">
            <label>Dung lượng được phép (GB)<input value={form.quota_gb} onChange={(event) => update('quota_gb', event.target.value)} type="number" min="0" step="0.01" placeholder="0 = không giới hạn" /></label>
            <label>Số user được phép<input value={form.user_limit} onChange={(event) => update('user_limit', event.target.value)} type="number" min="0" step="1" placeholder="0 = không giới hạn" /></label>
            <label>Ngưỡng cảnh báo %<input value={form.warning_threshold_percent} onChange={(event) => update('warning_threshold_percent', event.target.value)} type="number" min="1" max="100" step="1" required /></label>
          </div>
          <div className="info-strip">
            {isEdit
              ? (setup?.has_default_api_key
                ? 'Nếu website dùng chung key hệ thống thì có thể bỏ trống API key riêng. Khi lưu, admin sẽ dùng key mặc định để đồng bộ quota xuống quota endpoint, gọi /api/admin/package-config và chạy bảo trì từ xa.'
                : 'Khi lưu, admin sẽ đồng bộ dung lượng/user limit xuống `/application/site-usage/quota/config` và `/api/admin/package-config` của website.')
              : 'Với website mới, hãy ưu tiên dùng `Khởi tạo website` để chạy đủ các bước tạo subdomain, cấp SSL, copy code và clone DB. Form này mặc định chỉ lưu website vào admin; chỉ bật đồng bộ ngay nếu site đã có SSL và endpoint sẵn sàng.'}
          </div>
          {!isEdit && (
            <label className="check-line">
              <input type="checkbox" checked={form.sync_now} onChange={(event) => update('sync_now', event.target.checked)} />
              Đồng bộ quota ngay sau khi lưu
            </label>
          )}
          <label>Ghi chú<textarea value={form.notes} onChange={(event) => update('notes', event.target.value)} rows="4" /></label>
          <label className="check-line"><input type="checkbox" checked={form.enabled} onChange={(event) => update('enabled', event.target.checked)} />Đang theo dõi</label>
          {error && <div className="error-box">{error}</div>}
          <button className="primary-button full" disabled={busy}>{busy ? <Loader2 className="spin" size={16} /> : <Save size={16} />}Lưu website</button>
        </form>
      </aside>
    </div>
  );
}

function WebsiteDeletionDrawer({ website, onClose, onDeleted }) {
  const [confirmation, setConfirmation] = useState('');
  const [runNow, setRunNow] = useState(true);
  const [creatingRun, setCreatingRun] = useState(false);
  const [runningAll, setRunningAll] = useState(false);
  const [activeStepKey, setActiveStepKey] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [currentRun, setCurrentRun] = useState(null);

  const steps = currentRun?.steps || [];
  const progress = steps.length > 0
    ? Math.round((steps.filter((step) => step.status === 'success').length / steps.length) * 100)
    : 0;
  const canSubmit = confirmation === website.domain;
  const busy = creatingRun || runningAll || Boolean(activeStepKey);

  async function runSingleStep(runId, stepKey) {
    setActiveStepKey(stepKey);
    setCurrentRun((current) => (current?.id === runId ? patchRunStepState(current, stepKey, 'running') : current));

    try {
      const payload = await api(`/api/website-deletion/runs/${runId}/steps/${stepKey}`, { method: 'POST' });
      setCurrentRun(payload.data);
      return payload.data;
    } finally {
      setActiveStepKey('');
    }
  }

  async function runStepsSequentially(run) {
    let nextRun = run;
    const remainingSteps = (run?.steps || []).filter((step) => step.status !== 'success');

    for (const step of remainingSteps) {
      nextRun = await runSingleStep(nextRun.id, step.key);
      if (nextRun.status === 'failed' || nextRun.status === 'completed') {
        break;
      }
    }

    return nextRun;
  }

  async function createDeletionRun(event) {
    event.preventDefault();
    if (!canSubmit) {
      setError(`Nhập chính xác "${website.domain}" để xác nhận xóa.`);
      return;
    }

    setCreatingRun(true);
    setError('');
    setNotice('');
    try {
      const payload = await api(`/api/websites/${website.id}/deletion-runs`, {
        method: 'POST',
        body: {
          confirmation,
          run_now: false,
        },
      });
      setCurrentRun(payload.data);
      if (!runNow) {
        setNotice(`Đã tạo deletion run cho ${website.domain}.`);
        return;
      }

      setNotice(`Đã tạo deletion run cho ${website.domain}. Đang chạy từng bước xóa trên server...`);
      setRunningAll(true);
      const finalRun = await runStepsSequentially(payload.data);
      if (finalRun.status === 'completed') {
        await onDeleted();
        return;
      }
      if (finalRun.status === 'failed') {
        setError('Xóa website dừng ở bước lỗi. Kiểm tra log bên dưới rồi chạy lại bước lỗi.');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setCreatingRun(false);
      setRunningAll(false);
    }
  }

  async function executeStep(stepKey) {
    if (!currentRun) return;
    setError('');
    setNotice('');
    try {
      const nextRun = await runSingleStep(currentRun.id, stepKey);
      if (nextRun.status === 'completed') {
        await onDeleted();
        return;
      }
      if (nextRun.status === 'failed') {
        setError('Xóa website dừng ở bước lỗi. Kiểm tra log bên dưới rồi chạy lại bước lỗi.');
      }
    } catch (err) {
      setError(err.message);
    }
  }

  async function executeAll() {
    if (!currentRun) return;
    setRunningAll(true);
    setError('');
    setNotice('');
    try {
      const finalRun = await runStepsSequentially(currentRun);
      if (finalRun.status === 'completed') {
        await onDeleted();
        return;
      }
      if (finalRun.status === 'failed') {
        setError('Xóa website dừng ở bước lỗi. Kiểm tra log bên dưới rồi chạy lại bước lỗi.');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setRunningAll(false);
    }
  }

  return (
    <div className="drawer-backdrop">
      <aside className="drawer drawer-wide">
        <div className="panel-header">
          <div>
            <h2>Xóa sạch website</h2>
            <p>Xóa các tài nguyên đã tạo bởi script: subdomain Plesk, thư mục site, database và record trong admin.</p>
          </div>
          <button className="ghost-button" onClick={onClose}>Đóng</button>
        </div>

        {error && <div className="error-box wide">{error}</div>}
        {notice && <div className="success-box wide">{notice}</div>}

        <form onSubmit={createDeletionRun} className="drawer-form">
          <div className="danger-box">
            <strong>Thao tác này sẽ xóa thật trên server.</strong>
            <small>Website: {website.domain}. Database dự kiến: {website.domain.split('.')[0]}. Thư mục dự kiến: sites/{website.domain} và sites/private/{website.domain}.</small>
          </div>
          <label>
            Nhập domain để xác nhận xóa
            <input value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder={website.domain} required />
          </label>
          <label className="check-line">
            <input type="checkbox" checked={runNow} onChange={(event) => setRunNow(event.target.checked)} />
            Xóa ngay sau khi tạo run
          </label>
          <button className="danger-button full" disabled={busy || !canSubmit}>
            {busy ? <Loader2 className="spin" size={16} /> : <Trash2 size={16} />}
            {runNow ? 'Xóa website ngay' : 'Tạo deletion run'}
          </button>
        </form>

        {currentRun ? (
          <section className="provision-run-panel">
            <div className="panel-header compact">
              <div>
                <h2>Run xóa: {currentRun.domain}</h2>
                <p>Có thể chạy tiếp toàn bộ hoặc chạy lại riêng step lỗi.</p>
              </div>
              <div className="panel-actions">
                <ProvisionStatus status={currentRun.status} />
                <button className="danger-button" type="button" onClick={executeAll} disabled={busy || currentRun.status === 'completed'}>
                  {runningAll ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                  Chạy toàn bộ
                </button>
              </div>
            </div>

            <div className="provision-progress">
              <div className="batch-progress-head">
                <strong>{progress}% hoàn tất</strong>
                <span>{steps.filter((step) => step.status === 'success').length}/{steps.length} bước</span>
              </div>
              <div className="batch-progress-track">
                <span style={{ width: `${progress}%` }} />
              </div>
            </div>

            <div className="provision-step-list">
              {steps.map((step, index) => (
                <article className={`provision-step-card status-${step.status || 'pending'}`} key={step.key}>
                  <div className="provision-step-head">
                    <div>
                      <strong>Bước {index + 1}: {step.label}</strong>
                      <small>{step.description}</small>
                      {step.status === 'failed' && step.output ? <small className="step-error" title={step.output}><CircleAlert size={14} />Di chuột để xem lỗi đầy đủ</small> : null}
                    </div>
                    <button
                      type="button"
                      className={step.status === 'success' ? 'ghost-button' : 'danger-button'}
                      onClick={() => executeStep(step.key)}
                      disabled={busy || step.status === 'running' || step.status === 'success' || currentRun.status === 'completed'}
                    >
                      {activeStepKey === step.key ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                      {step.status === 'failed' ? 'Chạy lại bước lỗi' : (step.status === 'success' ? 'Đã xong' : 'Chạy bước này')}
                    </button>
                  </div>
                  <small className="command-preview">{step.command_preview}</small>
                  <pre className="provision-output">{step.output || 'Chưa có output cho bước này.'}</pre>
                </article>
              ))}
            </div>
          </section>
        ) : null}
      </aside>
    </div>
  );
}

function WebsiteProvisionDrawer({ onClose, onCreated }) {
  const [loading, setLoading] = useState(true);
  const [creatingRun, setCreatingRun] = useState(false);
  const [runningAll, setRunningAll] = useState(false);
  const [activeStepKey, setActiveStepKey] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [defaults, setDefaults] = useState({
    parent_domain: '',
    www_root: '',
    system_user: '',
    source_database: '',
    mysql_password_file: '/root/.mysql_pass',
    ssl_registration_email: '',
    website_username: '',
  });
  const [form, setForm] = useState({
    subdomain: '',
    parent_domain: '',
    www_root: '',
    system_user: '',
    source_database: '',
    mysql_password_file: '/root/.mysql_pass',
    ssl_registration_email: '',
    website_username: '',
    website_password: '',
  });
  const [autoRun, setAutoRun] = useState(true);
  const [runs, setRuns] = useState([]);
  const [currentRun, setCurrentRun] = useState(null);
  const busy = creatingRun || runningAll || Boolean(activeStepKey);

  async function loadRuns() {
    const payload = await api('/api/website-provision/runs');
    const nextDefaults = payload.defaults || {};
    setDefaults(nextDefaults);
    setRuns(payload.data || []);
    setForm((current) => ({
      ...current,
      parent_domain: current.parent_domain || nextDefaults.parent_domain || '',
      www_root: current.www_root || nextDefaults.www_root || '',
      system_user: current.system_user || nextDefaults.system_user || '',
      source_database: current.source_database || nextDefaults.source_database || '',
      mysql_password_file: current.mysql_password_file || nextDefaults.mysql_password_file || '/root/.mysql_pass',
      ssl_registration_email: current.ssl_registration_email || nextDefaults.ssl_registration_email || '',
      website_username: current.website_username || nextDefaults.website_username || '',
    }));
  }

  useEffect(() => {
    loadRuns()
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, []);

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function applyDefaults() {
    setForm((current) => ({
      ...current,
      parent_domain: defaults.parent_domain || current.parent_domain,
      www_root: defaults.www_root || current.www_root,
      system_user: defaults.system_user || current.system_user,
      mysql_password_file: defaults.mysql_password_file || current.mysql_password_file,
      ssl_registration_email: defaults.ssl_registration_email || current.ssl_registration_email,
      website_username: defaults.website_username || current.website_username,
    }));
  }

  async function runSingleStep(runId, stepKey) {
    setActiveStepKey(stepKey);
    setCurrentRun((current) => (current?.id === runId ? patchRunStepState(current, stepKey, 'running') : current));

    try {
      const payload = await api(`/api/website-provision/runs/${runId}/steps/${stepKey}`, { method: 'POST' });
      setCurrentRun(payload.data);
      await loadRuns();
      return payload.data;
    } finally {
      setActiveStepKey('');
    }
  }

  async function runStepsSequentially(run) {
    let nextRun = run;
    const remainingSteps = (run?.steps || []).filter((step) => step.status !== 'success');

    for (const step of remainingSteps) {
      nextRun = await runSingleStep(nextRun.id, step.key);
      if (nextRun.status === 'failed' || nextRun.status === 'completed') {
        break;
      }
    }

    return nextRun;
  }

  async function createRun(event) {
    event.preventDefault();
    setCreatingRun(true);
    setError('');
    setNotice('');

    try {
      const payload = await api('/api/website-provision/runs', { method: 'POST', body: form });
      setCurrentRun(payload.data);
      await loadRuns();
      if (!autoRun) {
        setNotice(`Đã tạo provisioning run cho ${payload.data.full_domain}. Bấm "Chạy toàn bộ" để tạo website trên server.`);
        return;
      }

      setNotice(`Đã tạo provisioning run cho ${payload.data.full_domain}. Đang chạy từng bước trên server...`);
      setRunningAll(true);
      const finalRun = await runStepsSequentially(payload.data);
      if (finalRun.status === 'completed') {
        setNotice(`Website ${finalRun.full_domain} đã khởi tạo xong.`);
        await onCreated();
        return;
      }
      if (finalRun.status === 'failed') {
        setError(`Khởi tạo website dừng ở bước lỗi. Xem log trong run ${finalRun.full_domain}.`);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setCreatingRun(false);
      setRunningAll(false);
    }
  }

  async function executeStep(stepKey) {
    if (!currentRun) return;
    setError('');
    setNotice('');

    try {
      const nextRun = await runSingleStep(currentRun.id, stepKey);
      if (nextRun.status === 'completed') {
        setNotice(`Website ${nextRun.full_domain} đã khởi tạo xong.`);
        await onCreated();
        return;
      }
      if (nextRun.status === 'failed') {
        setError(`Khởi tạo website dừng ở bước lỗi. Xem log trong run ${nextRun.full_domain}.`);
      }
    } catch (err) {
      setError(err.message);
    }
  }

  async function executeAll() {
    if (!currentRun) return;
    setRunningAll(true);
    setError('');
    setNotice('');

    try {
      const finalRun = await runStepsSequentially(currentRun);
      if (finalRun.status === 'completed') {
        setNotice(`Website ${finalRun.full_domain} đã khởi tạo xong.`);
        await onCreated();
        return;
      }
      if (finalRun.status === 'failed') {
        setError(`Khởi tạo website dừng ở bước lỗi. Xem log trong run ${finalRun.full_domain}.`);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setRunningAll(false);
    }
  }

  function selectRun(run) {
    setCurrentRun(run);
    setError('');
    setNotice('');
  }

  function resetRun() {
    setCurrentRun(null);
    setNotice('');
    setError('');
  }

  const currentRunSteps = currentRun?.steps || [];
  const currentRunProgress = currentRunSteps.length > 0
    ? Math.round((currentRunSteps.filter((step) => step.status === 'success').length / currentRunSteps.length) * 100)
    : 0;

  return (
    <div className="drawer-backdrop">
      <aside className="drawer drawer-wide">
        <div className="panel-header">
          <div>
            <h2>Tạo website mới</h2>
            <p>Chạy đúng theo script: tạo subdomain, SSL, copy folder, sửa settings và clone database.</p>
          </div>
          <div className="panel-actions">
            <button className="ghost-button" onClick={resetRun}>Run mới</button>
            <button className="ghost-button" onClick={onClose}>Đóng</button>
          </div>
        </div>

        {loading ? <SectionLoader label="Đang nạp cấu hình provisioning..." /> : (
          <>
            {error && <div className="error-box wide">{error}</div>}
            {notice && <div className="success-box wide">{notice}</div>}

            <form onSubmit={createRun} className="drawer-form">
              <label>Subdomain mới<input value={form.subdomain} onChange={(event) => update('subdomain', event.target.value)} placeholder="newcode" required /></label>

              <div className="info-strip">
                Hệ thống sẽ dùng đúng mặc định như script: domain cha <strong>{form.parent_domain || 'winmap.vn'}</strong>, www root <strong>{form.www_root || 'httpdocs_inventory'}</strong>, system user <strong>{form.system_user || 'ftp_winmap.vn'}</strong>, source DB <strong>{form.source_database || 'inventory'}</strong>, MySQL pass file <strong>{form.mysql_password_file || '/root/.mysql_pass'}</strong>, SSL email <strong>{form.ssl_registration_email || 'admin@winmap.vn'}</strong>.
              </div>

              <label className="check-line">
                <input type="checkbox" checked={autoRun} onChange={(event) => setAutoRun(event.target.checked)} />
                Tạo website ngay sau khi lưu run (chạy toàn bộ các bước như script)
              </label>

              <div className="wizard-actions split">
                <div className="provision-hint">
                  {form.subdomain && form.parent_domain ? <small>Website sẽ tạo: <strong>{form.subdomain}.{form.parent_domain}</strong></small> : <small>Điền subdomain và domain cha để tạo website.</small>}
                </div>
                <button className="primary-button" disabled={busy}>
                  {creatingRun ? <Loader2 className="spin" size={16} /> : <Plus size={16} />}
                  {autoRun ? 'Tạo website ngay' : 'Tạo provisioning run'}
                </button>
              </div>
            </form>

            {currentRun ? (
              <section className="provision-run-panel">
                <div className="panel-header compact">
                  <div>
                    <h2>Run hiện tại: {currentRun.full_domain}</h2>
                    <p>Chạy từng bước hoặc chạy toàn bộ phần còn lại. Step lỗi có thể chạy lại riêng.</p>
                  </div>
                  <div className="panel-actions">
                    <ProvisionStatus status={currentRun.status} />
                    <button className="primary-button" type="button" onClick={executeAll} disabled={busy || currentRun.status === 'completed'}>
                      {runningAll ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                      Chạy toàn bộ
                    </button>
                  </div>
                </div>

                <div className="provision-progress">
                  <div className="batch-progress-head">
                    <strong>{currentRunProgress}% hoàn tất</strong>
                    <span>{currentRunSteps.filter((step) => step.status === 'success').length}/{currentRunSteps.length} bước</span>
                  </div>
                  <div className="batch-progress-track">
                    <span style={{ width: `${currentRunProgress}%` }} />
                  </div>
                </div>

                <div className="provision-step-list">
                  {currentRun.steps.map((step, index) => (
                    <article
                      className={`provision-step-card status-${step.status || 'pending'}`}
                      key={step.key}
                      title={step.status === 'failed' && step.output ? step.output : ''}
                    >
                      <div className="provision-step-head">
                        <div>
                          <strong>Bước {index + 1}: {step.label}</strong>
                          <small>{step.description}</small>
                          {step.status === 'failed' && step.output ? <small className="step-error" title={step.output}><CircleAlert size={14} />Di chuột để xem lỗi đầy đủ</small> : null}
                        </div>
                        <button
                          type="button"
                          className={step.status === 'success' ? 'ghost-button' : 'primary-button'}
                          onClick={() => executeStep(step.key)}
                          disabled={busy || step.status === 'running' || step.status === 'success' || currentRun.status === 'completed'}
                        >
                          {activeStepKey === step.key ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                          {step.status === 'failed' ? 'Chạy lại bước lỗi' : (step.status === 'success' ? 'Đã xong' : 'Chạy bước này')}
                        </button>
                      </div>
                      <small className="command-preview">{step.command_preview}</small>
                      <pre className="provision-output">{step.output || 'Chưa có output cho bước này.'}</pre>
                    </article>
                  ))}
                </div>
              </section>
            ) : null}

            <section className="provision-history">
              <div className="panel-header compact">
                <div>
                  <h2>Run gần đây</h2>
                  <p>Chọn lại một lần chạy gần đây để xem log từng bước.</p>
                </div>
              </div>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Website</th>
                      <th>Trạng thái</th>
                      <th>Source DB</th>
                      <th>Thời gian</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {runs.length === 0 && (
                      <tr><td colSpan="5" className="empty-cell">Chưa có provisioning run nào.</td></tr>
                    )}
                    {runs.map((run) => (
                      <tr key={run.id}>
                        <td>
                          <strong>{run.full_domain}</strong>
                          <small>{run.system_user} · {run.www_root}</small>
                        </td>
                        <td><ProvisionStatus status={run.status} /></td>
                        <td><strong>{run.source_database}</strong></td>
                        <td><small>{run.created_at ? new Date(run.created_at).toLocaleString('vi-VN') : 'N/A'}</small></td>
                        <td className="row-actions">
                          <IconActionButton title="Xem run" icon={<Eye size={16} />} onClick={() => selectRun(run)} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        )}
      </aside>
    </div>
  );
}

function ProvisionStatus({ status }) {
  if (status === 'completed') return <span className="pill ok"><CheckCircle2 size={13} />Hoàn tất</span>;
  if (status === 'failed') return <span className="pill danger"><ShieldAlert size={13} />Lỗi</span>;
  if (status === 'running') return <span className="pill warn"><Loader2 className="spin" size={13} />Đang chạy</span>;
  return <span className="pill idle">Chờ chạy</span>;
}

function UsageBar({ percent }) {
  const safe = Math.max(0, Math.min(100, Number(percent) || 0));
  const tone = safe >= 90 ? 'danger' : safe >= 75 ? 'warn' : 'ok';
  return <div className="usage-bar"><span className={tone} style={{ width: `${safe}%` }} /></div>;
}

function StatusPill({ site }) {
  if (!site.enabled) return <span className="pill idle">Tạm dừng</span>;
  if (site.last_is_blocked) return <span className="pill danger"><ShieldAlert size={13} />Đã khóa</span>;
  if (site.last_is_warning) return <span className="pill warn"><AlertTriangle size={13} />Sắp đầy</span>;
  if (site.last_status === 'ok') return <span className="pill ok"><Activity size={13} />OK</span>;
  if (site.last_status === 'error') return <span className="pill danger">Lỗi</span>;
  return <span className="pill idle">Chưa lấy</span>;
}

createRoot(document.getElementById('root')).render(<App />);
