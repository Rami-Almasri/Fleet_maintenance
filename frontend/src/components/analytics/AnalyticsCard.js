// One card shell for every analytics strip, so a chart looks native to whichever
// skin its page wears:
//
//   variant="card"  → SectionCard   — the ordinary light page chrome
//   variant="opx"   → CommandPanel  — the Cockpit mission-control skin used by
//                     Vehicles and the Maintenance Cycle board
//
// The charts themselves are skin-agnostic (they draw from chartUtils tokens that
// nothing shadows), so switching a page's strip is a one-prop change.

import { SectionCard } from '../ui/Table';
import { CommandPanel } from '../ops';

export default function AnalyticsCard({
  variant = 'card',
  title,
  subtitle,
  actions,
  dotColor,
  className = '',
  bodyClass = '',
  children,
}) {
  if (variant === 'opx') {
    return (
      <CommandPanel title={title} meta={subtitle} dotColor={dotColor} action={actions} className={className}>
        {children}
      </CommandPanel>
    );
  }
  return (
    <SectionCard title={title} subtitle={subtitle} actions={actions} className={className} bodyClass={bodyClass}>
      {children}
    </SectionCard>
  );
}
