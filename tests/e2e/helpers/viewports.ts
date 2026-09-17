export type PortalViewport = {
  name: string;
  width: number;
  height: number;
};

/** Canonical viewports for Church Portal responsive regression. */
export const PORTAL_VIEWPORTS: PortalViewport[] = [
  { name: '360x800', width: 360, height: 800 },
  { name: '390x844', width: 390, height: 844 },
  { name: '430x932', width: 430, height: 932 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '1024x768', width: 1024, height: 768 },
  { name: '1440x900', width: 1440, height: 900 },
];

export const CAMPUS_NAV_COLLAPSE_PX = 820;
export const CAMPUS_COOKIE = 'portal_campus_id';
