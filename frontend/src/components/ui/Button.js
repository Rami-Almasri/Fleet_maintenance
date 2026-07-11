const VARIANTS = {
  primary:
    'bg-gradient-to-b from-indigo-500 to-indigo-600 text-white shadow-sm shadow-indigo-600/20 hover:from-indigo-500 hover:to-indigo-700 focus-visible:outline-indigo-600',
  secondary:
    'bg-white text-slate-700 ring-1 ring-inset ring-slate-200 shadow-sm hover:bg-slate-50 hover:ring-slate-300 focus-visible:outline-slate-400',
  ghost: 'text-slate-600 hover:bg-slate-100',
  danger:
    'bg-gradient-to-b from-red-500 to-red-600 text-white shadow-sm shadow-red-600/20 hover:to-red-700 focus-visible:outline-red-600',
  success:
    'bg-gradient-to-b from-emerald-500 to-emerald-600 text-white shadow-sm shadow-emerald-600/20 hover:to-emerald-700 focus-visible:outline-emerald-600',
  warning:
    'bg-gradient-to-b from-amber-500 to-amber-600 text-white shadow-sm shadow-amber-600/20 hover:to-amber-700 focus-visible:outline-amber-600',
};

const SIZES = {
  sm: 'px-3 py-1.5 text-xs gap-1.5',
  md: 'px-4 py-2.5 text-sm gap-2',
  lg: 'px-5 py-3 text-sm gap-2',
};

export default function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  className = '',
  children,
  ...props
}) {
  return (
    <button
      disabled={disabled || loading}
      className={`inline-flex items-center justify-center rounded-xl font-semibold transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 ${VARIANTS[variant] || VARIANTS.primary} ${SIZES[size] || SIZES.md} ${className}`}
      {...props}
    >
      {loading && (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
        </svg>
      )}
      {children}
    </button>
  );
}
