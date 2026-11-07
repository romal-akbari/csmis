"use client";

import { useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import Image from "next/image";
import {
  FiArrowRight,
  FiEye,
  FiEyeOff,
  FiLoader,
  FiLock,
  FiUser,
} from "react-icons/fi";
import { isApiFailure, login, type LoginResult } from "@/lib/api";
import styles from "./LoginPanel.module.css";

type Props = {
  onLogin: (result: LoginResult) => void;
};

/** Username/password gate for the API (§17 token auth). */
export function LoginPanel({ onLogin }: Props) {
  const [banner, setBanner] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [showPassword, setShowPassword] = useState(false);
  const [capsLock, setCapsLock] = useState(false);

  function checkCapsLock(event: KeyboardEvent<HTMLInputElement>) {
    setCapsLock(event.getModifierState("CapsLock"));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busyRef.current) return;
    // Read the displayed values, including password-manager autofill that may
    // not dispatch React change events.
    const form = event.currentTarget;
    const values = new FormData(form);
    const username = String(values.get("username") ?? "").trim();
    const password = String(values.get("password") ?? "");
    const required: Record<string, string> = {};
    if (!username) required.username = "Enter your username.";
    if (!password) required.password = "Enter your password.";
    setBanner("");
    setErrors(required);
    if (Object.keys(required).length > 0) {
      const field = form.elements.namedItem(Object.keys(required)[0]);
      if (field instanceof HTMLInputElement) field.focus();
      return;
    }

    busyRef.current = true;
    setBusy(true);
    try {
      const result = await login(username, password);
      onLogin(result);
    } catch (error) {
      if (isApiFailure(error)) {
        setErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) {
          setBanner(error.message);
        }
      } else {
        setBanner("Could not reach the server. Is the API running?");
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  return (
    <main className={styles.page}>
      <section className={styles.card} aria-labelledby="login-title">
        <div className={styles.cardContent}>
          <div className={styles.brand}>
            <Image src="/icon.svg" alt="" width={52} height={52} />
            <div>
              <span className={styles.brandName}>Sakhi Zada</span>
              <span className={styles.brandCompany}>UZBEK LTD</span>
            </div>
          </div>
          <header className={styles.formHeader}>
            <h1 id="login-title">Welcome back</h1>
            <p>Sign in to your workspace.</p>
          </header>

          <form onSubmit={submit} noValidate aria-busy={busy}>
            {banner && (
              <p className={styles.banner} role="alert">
                {banner}
              </p>
            )}
            <div className={styles.field}>
              <label htmlFor="login-username">Username</label>
              <div
                className={styles.inputWrap}
                data-invalid={Boolean(errors.username)}
              >
                <FiUser aria-hidden="true" />
                <input
                  id="login-username"
                  name="username"
                  placeholder="Enter your username"
                  autoComplete="username"
                  autoCapitalize="none"
                  spellCheck={false}
                  maxLength={60}
                  disabled={busy}
                  required
                  aria-invalid={Boolean(errors.username)}
                  aria-describedby={
                    errors.username ? "username-error" : undefined
                  }
                />
              </div>
              {errors.username && (
                <p
                  id="username-error"
                  className={styles.fieldError}
                  role="alert"
                >
                  {errors.username}
                </p>
              )}
            </div>

            <div className={styles.field}>
              <label htmlFor="login-password">Password</label>
              <div
                className={styles.inputWrap}
                data-invalid={Boolean(errors.password)}
              >
                <FiLock aria-hidden="true" />
                <input
                  id="login-password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  placeholder="Enter your password"
                  autoComplete="current-password"
                  maxLength={255}
                  disabled={busy}
                  required
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={
                    [
                      errors.password && "password-error",
                      capsLock && "caps-lock",
                    ]
                      .filter(Boolean)
                      .join(" ") || undefined
                  }
                  onKeyDown={checkCapsLock}
                  onKeyUp={checkCapsLock}
                  onBlur={() => setCapsLock(false)}
                />
                <button
                  className={styles.passwordToggle}
                  type="button"
                  aria-label={showPassword ? "Hide password" : "Show password"}
                  aria-pressed={showPassword}
                  aria-controls="login-password"
                  disabled={busy}
                  onClick={() => setShowPassword((visible) => !visible)}
                >
                  {showPassword ? (
                    <FiEyeOff aria-hidden="true" />
                  ) : (
                    <FiEye aria-hidden="true" />
                  )}
                </button>
              </div>
              {errors.password && (
                <p
                  id="password-error"
                  className={styles.fieldError}
                  role="alert"
                >
                  {errors.password}
                </p>
              )}
              {capsLock && (
                <p id="caps-lock" className={styles.capsLock} role="status">
                  Caps Lock is on.
                </p>
              )}
            </div>

            <button className={styles.submit} type="submit" disabled={busy}>
              <span>{busy ? "Signing you in..." : "Sign in"}</span>
              {busy ? (
                <FiLoader className={styles.spinner} aria-hidden="true" />
              ) : (
                <FiArrowRight aria-hidden="true" />
              )}
            </button>
          </form>
        </div>
      </section>
    </main>
  );
}
