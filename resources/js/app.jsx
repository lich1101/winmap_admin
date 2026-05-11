import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  Activity,
  AlertTriangle,
  Database,
  Globe2,
  HardDrive,
  Loader2,
  Lock,
  LogOut,
  Play,
  Plus,
  RefreshCw,
  Save,
  Server,
  ShieldCheck,
  ShieldAlert,
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
  const payload = text ? JSON.parse(text) : {};

  if (!response.ok) {
    const message = payload.message || Object.values(payload.errors || {}).flat().join(' ') || `HTTP ${response.status}`;
    const error = new Error(message);
    error.status = response.status;
    throw error;
  }

  return payload;
}

function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api('/api/me')
      .then((payload) => setUser(payload.user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return <FullPageLoader />;
  }

  if (!user) {
    return <LoginScreen onLogin={setUser} />;
  }

  return <Dashboard user={user} onLogout={() => setUser(null)} />;
}

function FullPageLoader() {
  return (
    <div className="center-screen">
      <Loader2 className="spin" size={34} />
      <p>Đang nạp bảng điều khiển...</p>
    </div>
  );
}

function LoginScreen({ onLogin }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');

    try {
      const payload = await api('/api/login', { method: 'POST', body: { email, password } });
      onLogin(payload.user);
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
        <p>Chỉ tài khoản có role <strong>administrator</strong> mới được vào màn kiểm soát dung lượng và terminal.</p>
        <form onSubmit={submit} className="login-form">
          <label>
            Email administrator
            <input value={email} onChange={(event) => setEmail(event.target.value)} type="email" autoComplete="email" required />
          </label>
          <label>
            Mật khẩu
            <input value={password} onChange={(event) => setPassword(event.target.value)} type="password" autoComplete="current-password" required />
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
          <h2>Quota từng website, dung lượng server, terminal có kiểm soát.</h2>
        </div>
        <div className="login-metrics">
          <span>MySQL</span>
          <span>Laravel API</span>
          <span>React Admin</span>
        </div>
      </aside>
    </main>
  );
}

function Dashboard({ user, onLogout }) {
  const [dashboard, setDashboard] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState(null);
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
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadDashboard();
  }, []);

  async function logout() {
    await api('/api/logout', { method: 'POST' });
    onLogout();
  }

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
        </div>
        <div className="topbar-actions">
          <span className="user-chip"><ShieldCheck size={16} />{user.name}</span>
          <button className="ghost-button" onClick={logout}><LogOut size={16} />Đăng xuất</button>
        </div>
      </header>

      {error && <div className="error-box wide">{error}</div>}
      {notice && <div className="success-box wide">{notice}</div>}

      {loading ? <FullPageLoader /> : (
        <>
          <section className="metrics-grid">
            <MetricCard icon={<Server />} label="Server used" value={server?.used_human || '0 B'} sub={`${server?.used_percent || 0}% của ${server?.total_human || '0 B'}`} tone="blue" percent={server?.used_percent || 0} />
            <MetricCard icon={<HardDrive />} label="Server free" value={server?.free_human || '0 B'} sub={server?.path || '/'} tone="green" />
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
              onDiscoverySync={syncDiscoveredWebsites}
            />
            <TerminalPanel onError={setError} />
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

function WebsitePanel({ websites, refreshingId, discovering, onRefresh, onEdit, onDelete, onCreate, onDiscoverySync }) {
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
              <tr><td colSpan="5" className="empty-cell">Chưa có website nào. Thêm endpoint `/application/site-usage/json` hoặc `/application/site-usage/all/json` để bắt đầu.</td></tr>
            )}
            {websites.map((site) => (
              <tr key={site.id} className={site.last_is_blocked ? 'danger-row' : (site.last_is_warning ? 'warn-row' : '')}>
                <td>
                  <strong>{site.name}</strong>
                  <small>{site.domain}</small>
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

function TerminalPanel({ onError }) {
  const [command, setCommand] = useState('df -h');
  const [cwd, setCwd] = useState('');
  const [output, setOutput] = useState('');
  const [busy, setBusy] = useState(false);
  const [history, setHistory] = useState([]);

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
          <p>Chỉ chạy command nằm trong allowlist và lưu audit log.</p>
        </div>
        <Terminal />
      </div>
      <form onSubmit={run} className="terminal-form">
        <input value={cwd} onChange={(event) => setCwd(event.target.value)} placeholder="cwd, bỏ trống = project hiện tại" />
        <div className="terminal-command-row">
          <input value={command} onChange={(event) => setCommand(event.target.value)} placeholder="df -h" />
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
            <p>Cấu hình quota và endpoint đo dung lượng.</p>
          </div>
          <button className="ghost-button" onClick={onClose}>Đóng</button>
        </div>
        <form onSubmit={submit} className="drawer-form">
          <label>Tên website<input value={form.name} onChange={(event) => update('name', event.target.value)} required /></label>
          <label>Domain<input value={form.domain} onChange={(event) => update('domain', event.target.value)} placeholder="enter.winmap.vn" required /></label>
          <label>Usage endpoint<input value={form.usage_endpoint_url} onChange={(event) => update('usage_endpoint_url', event.target.value)} placeholder={endpointHint || 'https://domain/application/site-usage/json'} required /></label>
          <label>Quota config endpoint<input value={form.config_endpoint_url} onChange={(event) => update('config_endpoint_url', event.target.value)} placeholder={configEndpointHint || 'https://domain/application/site-usage/quota/config'} /></label>
          <label>API key<input value={form.api_key} onChange={(event) => update('api_key', event.target.value)} placeholder={isEdit ? 'Bỏ trống để giữ key cũ' : 'X-Winmap-Site-Usage-Key'} /></label>
          <label>Quota GB<input value={form.quota_gb} onChange={(event) => update('quota_gb', event.target.value)} type="number" min="0" step="0.01" /></label>
          <label>Ngưỡng cảnh báo %<input value={form.warning_threshold_percent} onChange={(event) => update('warning_threshold_percent', event.target.value)} type="number" min="1" max="100" step="1" required /></label>
          <label>Ghi chú<textarea value={form.notes} onChange={(event) => update('notes', event.target.value)} rows="4" /></label>
          <label className="check-line"><input type="checkbox" checked={form.enabled} onChange={(event) => update('enabled', event.target.checked)} />Đang theo dõi</label>
          {error && <div className="error-box">{error}</div>}
          <button className="primary-button full" disabled={busy}>{busy ? <Loader2 className="spin" size={16} /> : <Save size={16} /> }Lưu website</button>
        </form>
      </aside>
    </div>
  );
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
