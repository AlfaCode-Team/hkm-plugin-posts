import { useCallback, useEffect, useRef, useState } from "react";
import {
  Bold,
  Italic,
  Underline as UnderlineIcon,
  Strikethrough,
  Heading2,
  Heading3,
  Quote,
  List,
  ListOrdered,
  Link as LinkIcon,
  Unlink,
  ImagePlus,
  RemoveFormatting,
  Undo2,
  Redo2,
} from "lucide-react";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@ui/dropdown-menu";
import type { ImagePickerOpener } from "./imagePicker";

const TOOLBAR_BTN =
  "inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-50";

/**
 * A dependency-free WYSIWYG editor for the post body in `html` format.
 *
 * ── WHY NO LIBRARY ─────────────────────────────────────────────────────────
 * The host project builds this plugin's UI with its OWN vite/node_modules, so
 * a TipTap/ProseMirror dependency would have to be installed in every host —
 * a cost the plugin cannot assume. The editor is a `contentEditable` surface
 * driving `document.execCommand`, which every current browser still supports
 * for these inline/block commands. It emits ordinary HTML.
 *
 * ── WHY THAT IS SAFE ───────────────────────────────────────────────────────
 * Whatever this produces is run through the SAME server pipeline the `html`
 * body format already used: league/commonmark passthrough + symfony/html-
 * sanitizer (see API/DTO/PostView.php). The stored `bodyHtml` is sanitized
 * before it is ever shown publicly, so a messy or hostile string typed here
 * cannot reach a reader. Paste is reduced to plain text to keep the stored
 * markup clean rather than carrying Word/Docs cruft.
 *
 * The component is intentionally UNCONTROLLED: React seeds the DOM once and
 * then stays out of the way (re-seeding only when `value` changes from the
 * OUTSIDE — a revision restore or a format switch — never on the keystrokes it
 * just emitted), so the caret never jumps mid-type.
 */
interface RichTextEditorProps {
  value: string;
  onChange: (html: string) => void;
  disabled?: boolean;
  placeholder?: string;
  /** Rough height in text rows, matched to the Markdown textarea it replaces. */
  rows?: number;
  /** When set (host registered it), the image button opens upload + gallery;
   *  otherwise it falls back to a URL prompt. */
  pickImage?: ImagePickerOpener | null;
}

type Icon = typeof Bold;
interface InlineCmd { command: string; label: string; icon: Icon }
interface BlockCmd { block: string; label: string; icon: Icon }

const INLINE: InlineCmd[] = [
  { command: "bold", label: "Bold", icon: Bold },
  { command: "italic", label: "Italic", icon: Italic },
  { command: "underline", label: "Underline", icon: UnderlineIcon },
  { command: "strikeThrough", label: "Strikethrough", icon: Strikethrough },
];

const BLOCKS: BlockCmd[] = [
  { block: "h2", label: "Heading", icon: Heading2 },
  { block: "h3", label: "Subheading", icon: Heading3 },
  { block: "blockquote", label: "Quote", icon: Quote },
];

const LISTS: InlineCmd[] = [
  { command: "insertUnorderedList", label: "Bulleted list", icon: List },
  { command: "insertOrderedList", label: "Numbered list", icon: ListOrdered },
];

/** execCommand is deprecated but still the only cross-browser rich-edit API. */
function exec(command: string, value?: string): void {
  try {
    document.execCommand(command, false, value);
  } catch {
    /* jsdom / a browser that dropped it — the toolbar button is then inert. */
  }
}

function queryState(command: string): boolean {
  try {
    return document.queryCommandState(command);
  } catch {
    return false;
  }
}

function currentBlock(): string {
  try {
    const value = document.queryCommandValue("formatBlock");
    return typeof value === "string" ? value.toLowerCase() : "";
  } catch {
    return "";
  }
}

export function RichTextEditor({ value, onChange, disabled = false, placeholder, rows = 18, pickImage }: RichTextEditorProps) {
  const ref = useRef<HTMLDivElement | null>(null);
  const lastHtml = useRef<string>(value);
  const [active, setActive] = useState<Record<string, boolean>>({});
  const [block, setBlock] = useState<string>("");

  // Seed once, and re-seed ONLY on an external change (restore / format swap)
  // — never echo back the HTML we just emitted, which would reset the caret.
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (value !== lastHtml.current && value !== el.innerHTML) {
      el.innerHTML = value ?? "";
      lastHtml.current = value ?? "";
    }
  }, [value]);

  const emit = useCallback(() => {
    const el = ref.current;
    if (!el) return;
    const html = el.innerHTML === "<br>" ? "" : el.innerHTML;
    lastHtml.current = html;
    onChange(html);
  }, [onChange]);

  const refreshState = useCallback(() => {
    setActive({
      bold: queryState("bold"),
      italic: queryState("italic"),
      underline: queryState("underline"),
      strikeThrough: queryState("strikeThrough"),
      insertUnorderedList: queryState("insertUnorderedList"),
      insertOrderedList: queryState("insertOrderedList"),
    });
    setBlock(currentBlock());
  }, []);

  // Toolbar state follows the caret while THIS editor holds the selection.
  useEffect(() => {
    function onSelection() {
      const el = ref.current;
      if (!el) return;
      const sel = window.getSelection();
      if (sel && sel.rangeCount > 0 && el.contains(sel.anchorNode)) refreshState();
    }
    document.addEventListener("selectionchange", onSelection);
    return () => document.removeEventListener("selectionchange", onSelection);
  }, [refreshState]);

  const run = useCallback(
    (fn: () => void) => {
      if (disabled) return;
      ref.current?.focus();
      fn();
      emit();
      refreshState();
    },
    [disabled, emit, refreshState],
  );

  function toggleBlock(tag: string) {
    // Second press on the same block returns it to a paragraph.
    const target = block === tag ? "p" : tag;
    exec("formatBlock", target === "p" ? "<p>" : `<${target}>`);
  }

  function makeLink() {
    const url = window.prompt("Link URL", "https://");
    if (url === null) return;
    const trimmed = url.trim();
    if (trimmed === "") {
      exec("unlink");
      return;
    }
    exec("createLink", trimmed);
  }

  // Inserting an image is async (a dialog) or blurring (a prompt), both of
  // which drop the editor's selection. So the caret is captured the moment the
  // image button is pressed and restored right before the insert, so the image
  // lands where the cursor was.
  const savedRange = useRef<Range | null>(null);
  function captureRange() {
    const sel = window.getSelection();
    savedRange.current =
      sel && sel.rangeCount > 0 && ref.current?.contains(sel.anchorNode) ? sel.getRangeAt(0).cloneRange() : null;
  }
  function insertImageUrl(url: string | null) {
    if (!url) return;
    const el = ref.current;
    if (!el) return;
    el.focus();
    if (savedRange.current) {
      const s = window.getSelection();
      s?.removeAllRanges();
      s?.addRange(savedRange.current);
    }
    exec("insertImage", url);
    emit();
  }
  async function imageFromPicker() {
    if (!pickImage) return;
    let url: string | null = null;
    try {
      url = await pickImage({ title: "Insert image" });
    } catch {
      url = null;
    }
    insertImageUrl(url);
  }
  function imageFromUrl() {
    const typed = window.prompt("Image URL", "https://");
    insertImageUrl(typed && typed.trim() !== "" ? typed.trim() : null);
  }

  // Keep pasted content plain so the stored markup stays clean; the browser
  // still turns the text's line breaks into paragraphs on insert.
  function onPaste(e: React.ClipboardEvent<HTMLDivElement>) {
    if (disabled) return;
    e.preventDefault();
    const text = e.clipboardData.getData("text/plain");
    exec("insertText", text);
    emit();
  }

  const minHeight = `${rows * 1.6}rem`;

  return (
    <div className="overflow-hidden rounded-lg border border-border focus-within:ring-1 focus-within:ring-ring">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 p-1">
        {INLINE.map((c) => (
          <ToolbarButton
            key={c.command}
            label={c.label}
            icon={c.icon}
            active={active[c.command]}
            disabled={disabled}
            onPress={() => run(() => exec(c.command))}
          />
        ))}
        <Divider />
        {BLOCKS.map((c) => (
          <ToolbarButton
            key={c.block}
            label={c.label}
            icon={c.icon}
            active={block === c.block}
            disabled={disabled}
            onPress={() => run(() => toggleBlock(c.block))}
          />
        ))}
        <Divider />
        {LISTS.map((c) => (
          <ToolbarButton
            key={c.command}
            label={c.label}
            icon={c.icon}
            active={active[c.command]}
            disabled={disabled}
            onPress={() => run(() => exec(c.command))}
          />
        ))}
        <Divider />
        <ToolbarButton label="Add link" icon={LinkIcon} disabled={disabled} onPress={() => run(makeLink)} />
        <ToolbarButton label="Remove link" icon={Unlink} disabled={disabled} onPress={() => run(() => exec("unlink"))} />
        {pickImage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                title="Insert image"
                aria-label="Insert image"
                disabled={disabled}
                onMouseDown={(e) => {
                  e.preventDefault();
                  captureRange();
                }}
                className={TOOLBAR_BTN}
              >
                <ImagePlus className="h-4 w-4" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuItem onSelect={() => void imageFromPicker()}>
                <ImagePlus className="mr-2 h-4 w-4" /> Upload or pick from gallery
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => imageFromUrl()}>
                <LinkIcon className="mr-2 h-4 w-4" /> Paste image URL
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : (
          <ToolbarButton
            label="Insert image by URL"
            icon={ImagePlus}
            disabled={disabled}
            onPress={() => {
              captureRange();
              imageFromUrl();
            }}
          />
        )}
        <ToolbarButton
          label="Clear formatting"
          icon={RemoveFormatting}
          disabled={disabled}
          onPress={() =>
            run(() => {
              exec("removeFormat");
              exec("formatBlock", "<p>");
            })
          }
        />
        <Divider />
        <ToolbarButton label="Undo" icon={Undo2} disabled={disabled} onPress={() => run(() => exec("undo"))} />
        <ToolbarButton label="Redo" icon={Redo2} disabled={disabled} onPress={() => run(() => exec("redo"))} />
      </div>

      <div
        ref={ref}
        contentEditable={!disabled}
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label="Post body"
        data-placeholder={placeholder ?? "Write the post body…"}
        onInput={emit}
        onBlur={emit}
        onPaste={onPaste}
        onKeyUp={refreshState}
        onMouseUp={refreshState}
        style={{ minHeight }}
        className={[
          "prose prose-sm max-w-none p-4 outline-none dark:prose-invert",
          "overflow-y-auto",
          disabled ? "cursor-not-allowed bg-muted/30 text-muted-foreground" : "bg-background",
          // Placeholder for the empty editor (contentEditable has none of its own).
          "empty:before:text-muted-foreground empty:before:content-[attr(data-placeholder)]",
        ].join(" ")}
      />
    </div>
  );
}

function ToolbarButton({
  label,
  icon: Icon,
  active = false,
  disabled = false,
  onPress,
}: {
  label: string;
  icon: typeof Bold;
  active?: boolean;
  disabled?: boolean;
  onPress: () => void;
}) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      aria-pressed={active}
      disabled={disabled}
      // mousedown, not click: preventDefault keeps the editor's selection,
      // which a focus change on click would otherwise collapse.
      onMouseDown={(e) => {
        e.preventDefault();
        onPress();
      }}
      className={[
        "inline-flex h-8 w-8 items-center justify-center rounded-md transition-colors",
        "disabled:pointer-events-none disabled:opacity-50",
        active ? "bg-primary/15 text-primary" : "text-muted-foreground hover:bg-muted hover:text-foreground",
      ].join(" ")}
    >
      <Icon className="h-4 w-4" />
    </button>
  );
}

function Divider() {
  return <span aria-hidden className="mx-0.5 h-5 w-px bg-border" />;
}

export default RichTextEditor;
