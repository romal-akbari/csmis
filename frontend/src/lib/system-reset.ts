export const RESET_CONFIRMATION = "RESET ALL DATA";
export type ResetPreview = {
  records_to_remove: number;
  kept: { users: number; roles: number; permissions: number };
  expires_at: string;
  preview_token: string;
};

export function validateReset(password: string, confirmation: string) {
  const errors: Record<string, string> = {};
  if (!password) errors.password = "Enter your current password.";
  else if (password.length > 255) errors.password = "Password is too long.";
  if (confirmation !== RESET_CONFIRMATION)
    errors.confirmation = "Type RESET ALL DATA exactly to continue.";
  return errors;
}
