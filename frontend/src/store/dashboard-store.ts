import {
  configureStore,
  createSlice,
  createAsyncThunk,
  type PayloadAction,
} from "@reduxjs/toolkit";
import { useDispatch, useSelector, useStore } from "react-redux";
import { apiFetch, isApiFailure } from "@/lib/api";

type Locale = "en" | "fa";

type DashboardState = {
  locale: Locale;
  localeSaving: boolean;
  localeError: string;
  sidebarOpen: boolean;
};

const initialState: DashboardState = {
  locale: "en",
  localeSaving: false,
  localeError: "",
  sidebarOpen: false,
};

/** Persist the top bar language preference with one request at a time. */
export const saveLocale = createAsyncThunk<
  Locale,
  Locale,
  { state: { dashboard: DashboardState }; rejectValue: string }
>(
  "dashboard/saveLocale",
  async (locale, { rejectWithValue }) => {
    try {
      await apiFetch("/profile/locale", {
        method: "PATCH",
        body: JSON.stringify({ locale }),
      });
      return locale;
    } catch (error) {
      return rejectWithValue(
        isApiFailure(error)
          ? error.message
          : "Could not save your language. Please try again.",
      );
    }
  },
  {
    condition: (locale, { getState }) => {
      const state = getState().dashboard;
      return !state.localeSaving && locale !== state.locale;
    },
  },
);

const dashboardSlice = createSlice({
  name: "dashboard",
  initialState,
  reducers: {
    setLocale: (state, action: PayloadAction<Locale>) => {
      state.locale = action.payload;
      state.localeError = "";
    },
    setSidebarOpen: (state, action: PayloadAction<boolean>) => {
      state.sidebarOpen = action.payload;
    },
  },
  extraReducers: (builder) => {
    builder
      .addCase(saveLocale.pending, (state) => {
        state.localeSaving = true;
        state.localeError = "";
      })
      .addCase(saveLocale.fulfilled, (state, action) => {
        state.localeSaving = false;
        state.locale = action.payload;
      })
      .addCase(saveLocale.rejected, (state, action) => {
        state.localeSaving = false;
        state.localeError = action.payload ?? "Could not save your language.";
      });
  },
});

export const { setLocale, setSidebarOpen } = dashboardSlice.actions;
export const makeStore = () =>
  configureStore({ reducer: { dashboard: dashboardSlice.reducer } });
export type AppStore = ReturnType<typeof makeStore>;
export type RootState = ReturnType<AppStore["getState"]>;
export type AppDispatch = AppStore["dispatch"];
export const useAppDispatch = useDispatch.withTypes<AppDispatch>();
export const useAppSelector = useSelector.withTypes<RootState>();
export const useAppStore = useStore.withTypes<AppStore>();
