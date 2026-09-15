// ONE ICON PER VEHICLE SYSTEM — the single answer to "which part of the car is this about?".
//
// The findings catalog's categories (config/maintenance_findings.php) are the systems an inspector
// works down: engine, brakes, tyres, electrical… Two screens draw them — the category list in
// FindingsPicker and the ranked rows in SuggestedChecks — and before this map each picked its own
// glyph, so the same system could arrive as a wrench on one and a cog on the other. The category key
// is the lookup, so a new category in the backend config shows up here as a deliberate one-line
// addition rather than silently as the fallback.
//
// A key we don't recognise falls back to the wrench: unlabelled, but never a blank column.

import Icon from '../ui/Icon';

const SYSTEM_ICON = {
  routine:      Icon.Wrench,
  engine:       Icon.Engine,
  brakes:       Icon.Disc,
  tyres:        Icon.Tyre,
  suspension:   Icon.Steering,
  transmission: Icon.Cog,
  electrical:   Icon.Bolt,
  ac:           Icon.Snowflake,
  bodywork:     Icon.Car,
  interior:     Icon.Seat,
  fluids:       Icon.Droplet,
  lights:       Icon.Sun,
  safety:       Icon.Shield,
};

/** The icon component for a catalog category key. Never null — callers render it unconditionally. */
export default function systemIcon(key) {
  return SYSTEM_ICON[String(key || '').toLowerCase()] || Icon.Wrench;
}
