import { useEffect, useReducer } from "react";

/**
 * Image-picker bridge — the plugin's seam for "upload / pick from gallery".
 *
 * The Posts plugin is host-agnostic and ships no media library of its own, so
 * it cannot know how a given host stores or browses images. Instead the HOST
 * registers an opener (e.g. hkmvote wires its `@shared/media` library here);
 * the editor then shows "Browse" / image buttons that call it and get back a
 * URL. With nothing registered the editor stays link-only — a plain URL field
 * and a URL prompt — so the plugin still works anywhere.
 *
 * The opener resolves to the chosen image URL, or `null` if the user cancels.
 */
export interface ImagePickerRequest {
  title?: string;
  /** The currently-set URL, so the host can preselect it. */
  value?: string | null;
}

export type ImagePickerOpener = (req?: ImagePickerRequest) => Promise<string | null>;

let opener: ImagePickerOpener | null = null;
const listeners = new Set<() => void>();

/** The host calls this (once, on mount) to provide upload + gallery. */
export function setImagePicker(fn: ImagePickerOpener | null): void {
  opener = fn;
  for (const l of listeners) l();
}

export function getImagePicker(): ImagePickerOpener | null {
  return opener;
}

/**
 * Subscribe to the registered opener. Re-renders when the host registers or
 * clears it, so a page that mounted before the host bridge still lights up its
 * "Browse" buttons the moment the bridge registers.
 */
export function useImagePicker(): ImagePickerOpener | null {
  const [, bump] = useReducer((n: number) => n + 1, 0);
  useEffect(() => {
    const l = () => bump();
    listeners.add(l);
    return () => {
      listeners.delete(l);
    };
  }, []);
  return opener;
}
