// Form field primitives with label + error support.

const baseInput =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 disabled:bg-slate-50 disabled:text-slate-400';

function Wrap({ label, error, required, children }) {
  return (
    <label className="block">
      {label && (
        <span className="mb-1 block text-sm font-medium text-slate-700">
          {label}
          {required && <span className="ms-0.5 text-red-500">*</span>}
        </span>
      )}
      {children}
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}

export function Input({ label, error, required, className = '', ...props }) {
  return (
    <Wrap label={label} error={error} required={required}>
      <input className={`${baseInput} ${error ? 'border-red-400' : ''} ${className}`} {...props} />
    </Wrap>
  );
}

export function Textarea({ label, error, required, className = '', rows = 3, ...props }) {
  return (
    <Wrap label={label} error={error} required={required}>
      <textarea rows={rows} className={`${baseInput} ${error ? 'border-red-400' : ''} ${className}`} {...props} />
    </Wrap>
  );
}

export function Select({ label, error, required, children, className = '', ...props }) {
  return (
    <Wrap label={label} error={error} required={required}>
      <select className={`${baseInput} capitalize ${error ? 'border-red-400' : ''} ${className}`} {...props}>
        {children}
      </select>
    </Wrap>
  );
}
