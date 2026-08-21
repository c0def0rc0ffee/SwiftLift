import { useEffect, useState } from 'react';
import { api } from '../api';
import { Avatar } from './Avatar';

/**
 * <summary>
 * Shape of a blocked user as returned by <c>api.listBlocks</c>.
 * </summary>
 */
type BlockedUser = {
  /** User id of the blocked person. */
  id: number;
  /** Their display name. */
  display_name: string;
  /** Avatar URL or <c>null</c>. */
  avatar_url: string | null;
  /** Optional free text reason captured at block time. */
  reason: string | null;
  /** ISO timestamp of when the block was created. */
  created_at: string;
};

/**
 * <summary>
 * Profile-page section that lists the people the signed-in user has
 * blocked, with an inline "Unblock" action for each row.
 * </summary>
 * <remarks>
 * Loads blocks once on mount. Unblock performs an optimistic removal
 * from the list on success. Errors fall back to an inline error line
 * rather than a toast so the user sees them in context.
 * </remarks>
 */
export function BlockList() {
  const [blocks, setBlocks] = useState<BlockedUser[] | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [err, setErr]       = useState<string | null>(null);

  /**
   * <summary>
   * Fetches the current list of blocked users from the API and stores it.
   * </summary>
   * <remarks>Errors are surfaced into local state and rendered inline.</remarks>
   */
  async function load() {
    try {
      const r = await api.listBlocks();
      setBlocks(r.blocks);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    }
  }
  useEffect(() => { load(); }, []);

  /**
   * <summary>
   * Unblocks the given user and optimistically drops them from the list.
   * </summary>
   * <param name="id">User id to unblock.</param>
   */
  async function unblock(id: number) {
    setBusyId(id); setErr(null);
    try {
      await api.unblockUser(id);
      setBlocks(prev => prev ? prev.filter(b => b.id !== id) : prev);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusyId(null);
    }
  }

  return (
    <section>
      <h3>Blocked users</h3>
      <p className="muted small">People you've blocked don't see your journeys and can't appear in your matches.</p>

      {err && <div className="error small">{err}</div>}

      {blocks === null && <div className="muted small">Loading…</div>}
      {blocks && blocks.length === 0 && (
        <div className="muted small">You haven't blocked anyone.</div>
      )}

      {blocks && blocks.length > 0 && (
        <ul className="block-list">
          {blocks.map(b => (
            <li key={b.id} className="block-row">
              <Avatar name={b.display_name} url={b.avatar_url} size={32} />
              <div className="block-body">
                <div><strong>{b.display_name}</strong></div>
                {b.reason && <div className="muted small">&ldquo;{b.reason}&rdquo;</div>}
              </div>
              <button className="small" disabled={busyId === b.id} onClick={() => unblock(b.id)}>
                {busyId === b.id ? '…' : 'Unblock'}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
