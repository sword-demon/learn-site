/** True when sanitized HTML still has visible text (ignores empty WangEditor shells). */
export function hasRichHtml(html: string | null | undefined): boolean {
  if (!html?.trim()) return false;
  const text = html
    .replace(/<br\s*\/?>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .trim();
  return text.length > 0;
}
