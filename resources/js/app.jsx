import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  CircleAlert,
  Database,
  Globe2,
  HardDrive,
  KeyRound,
  Loader2,
  Lock,
  LogOut,
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
        <div className="brand-mark"><ShieldCheck size={30} /></div>
        <h1>Winmap Admin</h1>
        <p>Đăng nhập bằng administrator để vào bước setup server, project Drupal 7 multisite và màn kiểm soát dung lượng.</p>
        <form onSubmit={submit} className="login-form">
          <label>
            Tài khoản administrator
            <input
              name="account"
              value={account}
              onChange={(event) => setAccount(event.target.value)}
              type="text"
              autoComplete="username"
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
              required
            />
          </label>
          {error && <div className="error-box">{error}</div>}
          <button type="submit" disabled={busy} className="primary-button full">
            {busy ? <Loader2 className="spin" size={18} /> : <Lock size={18} />}
            Đăng nhập
          </button>
        </form>
      </section>
      <aside className="login-aside">
        <div>
          <span className="small-label">Storage control plane</span>
          <h2>Setup server, quét multisite, khóa website khi vượt quota.</h2>
        </div>
        <div className="login-metrics">
          <span>Drupal 7</span>
          <span>SSH Discovery</span>
          <span>React Admin</span>
        </div>
      </aside>
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
  const [defaultAccount, setDefaultAccount] = useState('');
  const [defaultPassword, setDefaultPassword] = useState('');
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
      website_username: site.website_username || defaultAccount,
      website_password: site.website_password || defaultPassword,
      has_website_password: site.has_website_password || Boolean(defaultPassword || site.website_password),
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
          websites: sites.map((site) => ({
            name: site.name,
            domain: site.domain,
            usage_endpoint_url: site.usage_endpoint_url,
            config_endpoint_url: site.config_endpoint_url,
            discovery_root: site.discovery_root,
            discovery_conf_path: site.discovery_conf_path,
            website_username: site.website_username,
            website_password: site.website_password || '',
            enabled: site.enabled,
            warning_threshold_percent: site.warning_threshold_percent || 85,
            quota_bytes: site.quota_bytes || 0,
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
          <span className="small-label">Winmap Admin Setup</span>
          <h1>Thiết lập server và multisite Drupal</h1>
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
                  <p>Phần này lưu thông tin truy cập từng website, đồng thời chọn website dùng để xác thực administrator cho backend.</p>
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
                  <strong>Credential mặc định</strong>
                  <p>Nếu nhiều site dùng cùng một tài khoản, điền ở đây rồi áp dụng xuống toàn bộ danh sách.</p>
                </div>
                <div className="form-grid three-up">
                  <label>Tài khoản mặc định<input value={defaultAccount} onChange={(event) => setDefaultAccount(event.target.value)} placeholder="administrator" /></label>
                  <label>Mật khẩu mặc định<input value={defaultPassword} onChange={(event) => setDefaultPassword(event.target.value)} type="password" placeholder="Mật khẩu cho nhiều site" /></label>
                  <div className="button-stack">
                    <button type="button" className="ghost-button" onClick={applyDefaultsToSites}><KeyRound size={16} />Áp dụng xuống danh sách</button>
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

                    <div className="form-grid three-up">
                      <label>Tài khoản website<input value={site.website_username} onChange={(event) => updateSite(index, 'website_username', event.target.value)} placeholder="administrator" required /></label>
                      <label>Mật khẩu website<input value={site.website_password} onChange={(event) => updateSite(index, 'website_password', event.target.value)} type="password" placeholder={site.has_website_password ? 'Để trống để giữ mật khẩu đã lưu' : 'Mật khẩu của site'} /></label>
                      <label>Ngưỡng cảnh báo %<input value={site.warning_threshold_percent} onChange={(event) => updateSite(index, 'warning_threshold_percent', event.target.value)} type="number" min="1" max="100" /></label>
                    </div>

                    <div className="site-card-foot">
                      <label className="check-line"><input type="checkbox" checked={site.enabled} onChange={(event) => updateSite(index, 'enabled', event.target.checked)} />Theo dõi website này</label>
                      <small>{site.has_website_password ? 'Đã có mật khẩu lưu trong hệ thống.' : 'Chưa có mật khẩu lưu cho website này.'}</small>
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
    enabled: site.enabled ?? true,
    warning_threshold_percent: site.warning_threshold_percent || 85,
    quota_bytes: site.quota_bytes || 0,
  };
}

function Dashboard({ user, setup, onLogout, onOpenSetup }) {
  const [dashboard, setDashboard] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState(null);
  const [provisioningOpen, setProvisioningOpen] = useState(false);
  const [refreshingId, setRefreshingId] = useState(null);
  const [discovering, setDiscovering] = useState(false);

  const websites = dashboard?.websites || [];
  const server = dashboard?.server;
  const summary = dashboard?.summary;

  async function loadDashboard() {
    setLoading(true);
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
      setLoading(false);
    }
  }

  useEffect(() => {
    loadDashboard();
  }, []);

  async function refreshWebsite(id) {
    setRefreshingId(id);
    setNotice('');
    try {
      await api(`/api/websites/${id}/refresh`, { method: 'POST' });
      await loadDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setRefreshingId(null);
    }
  }

  async function deleteWebsite(id) {
    if (!window.confirm('Xóa website này khỏi danh sách giám sát?')) return;
    await api(`/api/websites/${id}`, { method: 'DELETE' });
    await loadDashboard();
  }

  async function syncDiscoveredWebsites() {
    setDiscovering(true);
    setError('');
    setNotice('');
    try {
      const payload = await api('/api/websites/discovery/sync', { method: 'POST' });
      setNotice(payload.message || 'Đã quét multisite thành công.');
      await loadDashboard();
    } catch (err) {
      setError(err.message);
    } finally {
      setDiscovering(false);
    }
  }

  return (
    <main className="app-shell">
      <header className="topbar">
        <div>
          <span className="small-label">Winmap Admin Backend</span>
          <h1>Kiểm soát dung lượng website</h1>
          <p className="topbar-sub">{setup.server_host}:{setup.server_port} · {setup.drupal_project_path} · admin auth: {setup.auth_site_domain}</p>
        </div>
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
            <MetricCard icon={<Server />} label="Server used" value={server?.used_human || '0 B'} sub={`${server?.used_percent || 0}% của ${server?.total_human || '0 B'}`} tone="blue" percent={server?.used_percent || 0} />
            <MetricCard icon={<HardDrive />} label="Server free" value={server?.free_human || '0 B'} sub={`${server?.remote_host || ''} · ${server?.path || '/'}`} tone="green" />
            <MetricCard icon={<Globe2 />} label="Website" value={summary?.website_count || 0} sub={`${summary?.warning_count || 0} sắp đầy · ${summary?.over_quota_count || 0} đã khóa`} tone="amber" />
            <MetricCard icon={<Database />} label="Tổng project" value={summary?.total_project_human || '0 B'} sub="Disk + database đã check gần nhất" tone="slate" />
          </section>

          <section className="content-grid">
            <WebsitePanel
              websites={websites}
              refreshingId={refreshingId}
              discovering={discovering}
              onRefresh={refreshWebsite}
              onEdit={setEditing}
              onDelete={deleteWebsite}
              onCreate={() => setEditing({})}
              onProvision={() => setProvisioningOpen(true)}
              onDiscoverySync={syncDiscoveredWebsites}
            />
            <TerminalPanel onError={setError} projectPath={setup.drupal_project_path} />
          </section>
        </>
      )}

      {editing !== null && (
        <WebsiteDrawer
          website={editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await loadDashboard();
          }}
        />
      )}

      {provisioningOpen && (
        <WebsiteProvisionDrawer
          onClose={() => setProvisioningOpen(false)}
          onCreated={async () => {
            await loadDashboard();
            setNotice('Khởi tạo website hoàn tất và đã thêm vào danh sách giám sát.');
          }}
        />
      )}
    </main>
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

function WebsitePanel({ websites, refreshingId, discovering, onRefresh, onEdit, onDelete, onCreate, onProvision, onDiscoverySync }) {
  return (
    <section className="panel website-panel">
      <div className="panel-header">
        <div>
          <h2>Website đang giám sát</h2>
          <p>Quota, cảnh báo gần đầy và trạng thái khóa được đồng bộ trực tiếp xuống từng Drupal site.</p>
        </div>
        <div className="panel-actions">
          <button className="ghost-button" onClick={onDiscoverySync} disabled={discovering}>
            {discovering ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
            Quét multisite
          </button>
          <button className="ghost-button" onClick={onProvision}><Server size={16} />Khởi tạo website</button>
          <button className="primary-button" onClick={onCreate}><Plus size={17} />Thêm website</button>
        </div>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Website</th>
              <th>Hiện tại</th>
              <th>Quota</th>
              <th>Trạng thái</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {websites.length === 0 && (
              <tr><td colSpan="5" className="empty-cell">Chưa có website nào. Chạy setup hoặc quét multisite để bắt đầu.</td></tr>
            )}
            {websites.map((site) => (
              <tr key={site.id} className={site.last_is_blocked ? 'danger-row' : (site.last_is_warning ? 'warn-row' : '')}>
                <td>
                  <strong>{site.name}</strong>
                  <small>{site.domain}</small>
                  <small>Credential: {site.website_username || 'chưa điền'} · {site.has_website_password ? 'đã lưu mật khẩu' : 'chưa có mật khẩu'}</small>
                  {site.has_api_key ? null : <small className="muted-alert">Chưa có API key, quota chưa đẩy tự động xuống site.</small>}
                </td>
                <td>
                  <strong>{site.last_project_human}</strong>
                  <small>Disk {site.last_disk_human} · DB {site.last_database_human}</small>
                  <UsageBar percent={site.last_usage_percent || 0} />
                </td>
                <td>
                  <strong>{site.quota_human}</strong>
                  <small>Cảnh báo từ {site.warning_threshold_percent}%</small>
                </td>
                <td>
                  <StatusPill site={site} />
                  <small>{site.last_checked_at ? new Date(site.last_checked_at).toLocaleString('vi-VN') : 'Chưa check'}</small>
                  {site.last_sync_status === 'error' && <small className="muted-alert">Sync quota lỗi: {site.last_sync_error}</small>}
                </td>
                <td className="row-actions">
                  <button onClick={() => onRefresh(site.id)} title="Refresh">
                    {refreshingId === site.id ? <Loader2 className="spin" size={16} /> : <RefreshCw size={16} />}
                  </button>
                  <button onClick={() => onEdit(site)} title="Sửa"><Save size={16} /></button>
                  <button onClick={() => onDelete(site.id)} title="Xóa"><Trash2 size={16} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
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
          <h2>Terminal có kiểm soát</h2>
          <p>Command được chạy qua SSH trên server Drupal đã setup, chỉ cho phép allowlist và lưu audit log.</p>
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

function WebsiteDrawer({ website, onClose, onSaved }) {
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
    warning_threshold_percent: website.warning_threshold_percent || 85,
    enabled: website.enabled ?? true,
    notes: website.notes || '',
  });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const endpointHint = useMemo(() => {
    if (!form.domain) return '';
    return `https://${form.domain}/application/site-usage/json`;
  }, [form.domain]);

  const configEndpointHint = useMemo(() => {
    if (!form.domain) return '';
    return `https://${form.domain}/application/site-usage/quota/config`;
  }, [form.domain]);

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const method = isEdit ? 'PUT' : 'POST';
      const url = isEdit ? `/api/websites/${website.id}` : '/api/websites';
      await api(url, { method, body: form });
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
            <h2>{isEdit ? 'Sửa website' : 'Thêm website'}</h2>
            <p>Cấu hình quota, endpoint đo dung lượng và credential quản trị website.</p>
          </div>
          <button className="ghost-button" onClick={onClose}>Đóng</button>
        </div>
        <form onSubmit={submit} className="drawer-form">
          <label>Tên website<input value={form.name} onChange={(event) => update('name', event.target.value)} required /></label>
          <label>Domain<input value={form.domain} onChange={(event) => update('domain', event.target.value)} placeholder="enter.winmap.vn" required /></label>
          <label>Usage endpoint<input value={form.usage_endpoint_url} onChange={(event) => update('usage_endpoint_url', event.target.value)} placeholder={endpointHint || 'https://domain/application/site-usage/json'} required /></label>
          <label>Quota config endpoint<input value={form.config_endpoint_url} onChange={(event) => update('config_endpoint_url', event.target.value)} placeholder={configEndpointHint || 'https://domain/application/site-usage/quota/config'} /></label>
          <label>API key<input value={form.api_key} onChange={(event) => update('api_key', event.target.value)} placeholder={isEdit ? 'Bỏ trống để giữ key cũ' : 'X-Winmap-Site-Usage-Key'} /></label>
          <div className="form-grid two-up">
            <label>Tài khoản website<input value={form.website_username} onChange={(event) => update('website_username', event.target.value)} placeholder="administrator" /></label>
            <label>Mật khẩu website<input value={form.website_password} onChange={(event) => update('website_password', event.target.value)} type="password" placeholder={website.has_website_password ? 'Bỏ trống để giữ mật khẩu cũ' : 'Mật khẩu website'} /></label>
          </div>
          <label>Quota GB<input value={form.quota_gb} onChange={(event) => update('quota_gb', event.target.value)} type="number" min="0" step="0.01" /></label>
          <label>Ngưỡng cảnh báo %<input value={form.warning_threshold_percent} onChange={(event) => update('warning_threshold_percent', event.target.value)} type="number" min="1" max="100" step="1" required /></label>
          <label>Ghi chú<textarea value={form.notes} onChange={(event) => update('notes', event.target.value)} rows="4" /></label>
          <label className="check-line"><input type="checkbox" checked={form.enabled} onChange={(event) => update('enabled', event.target.checked)} />Đang theo dõi</label>
          {error && <div className="error-box">{error}</div>}
          <button className="primary-button full" disabled={busy}>{busy ? <Loader2 className="spin" size={16} /> : <Save size={16} />}Lưu website</button>
        </form>
      </aside>
    </div>
  );
}

function WebsiteProvisionDrawer({ onClose, onCreated }) {
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
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
  const [runs, setRuns] = useState([]);
  const [currentRun, setCurrentRun] = useState(null);

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

  async function createRun(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');

    try {
      const payload = await api('/api/website-provision/runs', { method: 'POST', body: form });
      setCurrentRun(payload.data);
      await loadRuns();
      setNotice(`Đã tạo provisioning run cho ${payload.data.full_domain}.`);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function executeStep(stepKey) {
    if (!currentRun) return;
    setBusy(true);
    setError('');
    setNotice('');

    try {
      const payload = await api(`/api/website-provision/runs/${currentRun.id}/steps/${stepKey}`, { method: 'POST' });
      setCurrentRun(payload.data);
      await loadRuns();
      if (payload.data.status === 'completed') {
        setNotice(`Website ${payload.data.full_domain} đã khởi tạo xong.`);
        await onCreated();
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function executeAll() {
    if (!currentRun) return;
    setBusy(true);
    setError('');
    setNotice('');

    try {
      const payload = await api(`/api/website-provision/runs/${currentRun.id}/run-all`, { method: 'POST' });
      setCurrentRun(payload.data);
      await loadRuns();
      if (payload.data.status === 'completed') {
        setNotice(`Website ${payload.data.full_domain} đã khởi tạo xong.`);
        await onCreated();
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
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

  return (
    <div className="drawer-backdrop">
      <aside className="drawer drawer-wide">
        <div className="panel-header">
          <div>
            <h2>Khởi tạo website từng bước</h2>
            <p>Tách đúng theo script: tạo subdomain, SSL, copy folder, sửa settings và clone database.</p>
          </div>
          <div className="panel-actions">
            <button className="ghost-button" onClick={resetRun}>Run mới</button>
            <button className="ghost-button" onClick={onClose}>Đóng</button>
          </div>
        </div>

        {loading ? <FullPageLoader label="Đang nạp cấu hình provisioning..." /> : (
          <>
            {error && <div className="error-box wide">{error}</div>}
            {notice && <div className="success-box wide">{notice}</div>}

            <form onSubmit={createRun} className="drawer-form">
              <div className="form-grid three-up">
                <label>Subdomain mới<input value={form.subdomain} onChange={(event) => update('subdomain', event.target.value)} placeholder="newcode" required /></label>
                <label>Domain cha<input value={form.parent_domain} onChange={(event) => update('parent_domain', event.target.value)} placeholder="winmap.vn" required /></label>
                <div className="button-stack">
                  <button type="button" className="ghost-button" onClick={applyDefaults}><RefreshCw size={16} />Nạp mặc định</button>
                </div>
              </div>

              <div className="form-grid three-up">
                <label>www root<input value={form.www_root} onChange={(event) => update('www_root', event.target.value)} placeholder="httpdocs_inventory" required /></label>
                <label>System user<input value={form.system_user} onChange={(event) => update('system_user', event.target.value)} placeholder="ftp_winmap.vn" required /></label>
                <label>Source database<input value={form.source_database} onChange={(event) => update('source_database', event.target.value)} placeholder="inventory" required /></label>
              </div>

              <div className="form-grid three-up">
                <label>MySQL root password file<input value={form.mysql_password_file} onChange={(event) => update('mysql_password_file', event.target.value)} placeholder="/root/.mysql_pass" required /></label>
                <label>Email đăng ký SSL<input value={form.ssl_registration_email} onChange={(event) => update('ssl_registration_email', event.target.value)} type="email" placeholder="admin@winmap.vn" required /></label>
                <label>Tài khoản website<input value={form.website_username} onChange={(event) => update('website_username', event.target.value)} placeholder="administrator" /></label>
              </div>

              <label>Mật khẩu website<input value={form.website_password} onChange={(event) => update('website_password', event.target.value)} type="password" placeholder="Lưu kèm website vừa tạo để quản lý sau này" /></label>

              <div className="wizard-actions split">
                <div className="provision-hint">
                  {form.subdomain && form.parent_domain ? <small>Website sẽ tạo: <strong>{form.subdomain}.{form.parent_domain}</strong></small> : <small>Điền subdomain và domain cha để tạo website.</small>}
                </div>
                <button className="primary-button" disabled={busy}>
                  {busy ? <Loader2 className="spin" size={16} /> : <Plus size={16} />}
                  Tạo provisioning run
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
                      {busy ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                      Chạy toàn bộ
                    </button>
                  </div>
                </div>

                <div className="provision-step-list">
                  {currentRun.steps.map((step, index) => (
                    <article className={`provision-step-card status-${step.status || 'pending'}`} key={step.key}>
                      <div className="provision-step-head">
                        <div>
                          <strong>Bước {index + 1}: {step.label}</strong>
                          <small>{step.description}</small>
                        </div>
                        <button
                          type="button"
                          className={step.status === 'success' ? 'ghost-button' : 'primary-button'}
                          onClick={() => executeStep(step.key)}
                          disabled={busy || step.status === 'running' || currentRun.status === 'completed'}
                        >
                          {busy && currentRun.current_step === step.key ? <Loader2 className="spin" size={16} /> : <Play size={16} />}
                          {step.status === 'success' ? 'Chạy lại' : 'Chạy bước này'}
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
                          <button type="button" onClick={() => selectRun(run)} title="Xem run"><Save size={16} /></button>
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
  return <span className="pill idle">Pending</span>;
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
  return <span className="pill idle">Pending</span>;
}

createRoot(document.getElementById('root')).render(<App />);
