import { foundationText } from "./foundation-i18n";

const dari: Record<string, string> = {
  "Destination stock location": "محل دریافت موجودی",
  "Business model": "مدل کسب‌وکار",
  buy_resell: "خرید و فروش مجدد",
  company_funded: "تولید با تمویل شرکت",
  partnership: "شراکت در سود",
  sold: "فروخته‌شده",
  production_receive: "دریافت از تولید",
  sale: "فروش",
  "at_washing": "در مرکز شستشو",
  "washing_completed": "شستشو تکمیل شده",
  "production_completed": "تولید تکمیل شده",
  "wash_sent": "ارسال به شستشو",
  "wash_completed": "تکمیل شستشو",
  "wash_returned": "بازگشت از شستشو",
  "washing_dispatch": "ارسال به شستشو",
  "washing_complete": "تکمیل شستشو",
  "washing_return": "بازگشت از شستشو",
  "Washing visits": "دوره‌های شستشو",
  "Washing batch": "بستهٔ شستشو",
  "Washing facility": "مرکز شستشو",
  "Sent date": "تاریخ ارسال",
  "Completed date": "تاریخ تکمیل",
  "Returned date": "تاریخ بازگشت",
  "Washing costs": "هزینه‌های شستشو",
  "Allocated cost": "هزینهٔ تخصیص یافته",
  "No washing visits yet.": "هنوز دورهٔ شستشو ثبت نشده است.",
  "No washing costs allocated yet.": "هنوز هزینهٔ شستشو تخصیص نیافته است.",
  "Purchase details": "جزئیات خرید",
  "Show purchase details": "نمایش جزئیات خرید",
  "Hide purchase details": "بستن جزئیات خرید",
  "Purchase date cannot precede the previous stock event.":
    "تاریخ خرید نباید پیش از رویداد قبلی موجودی باشد.",
  "Not received (cancel purchase items)": "دریافت‌نشده (لغو اقلام خرید)",
  "Save draft": "ذخیره مسوده",
  "New purchase draft": "مسوده جدید خرید",
  "Edit purchase draft": "ویرایش مسوده خرید",
  "Edit draft": "ویرایش مسوده",
  "Post draft": "ثبت نهایی مسوده",
  "Cancel draft": "لغو مسوده",
  "Void purchase": "لغو خرید",
  "Correct stock": "اصلاح موجودی",
  "Return carpets": "برگشت قالین‌ها",
  "Record refund": "ثبت بازپرداخت",
  "View record": "مشاهده سند",
  "Reverse refund": "برگشت بازپرداخت",
  "Refund reversed.": "بازپرداخت برگشت داده شد.",
  "Choose purchase": "انتخاب خرید",
  "Correction reference": "شماره اصلاح",
  "Correction type": "نوع اصلاح",
  "Correction date": "تاریخ اصلاح",
  "Cancellation date": "تاریخ لغو",
  "Return reference": "شماره برگشت",
  "Return date": "تاریخ برگشت",
  "Refund reference": "شماره بازپرداخت",
  "Refund date": "تاریخ بازپرداخت",
  "Returned amount": "مبلغ برگشت",
  "Refunded amount": "مبلغ بازپرداخت",
  "Net purchase amount": "مبلغ خالص خرید",
  "Credit owed by weaver": "طلب از بافنده",
  "Purchase units per refund unit":
    "واحد ارز خرید در برابر یک واحد ارز بازپرداخت",
  draft: "مسوده",
  posted: "ثبت نهایی",
  cancelled: "لغوشده",
  void: "لغوشده",
  all: "همه",
  missing: "مفقود",
  found: "یافت‌شده",
  relocate: "اصلاح محل",
  returned: "برگشت‌شده",
  adjustment: "اصلاح",
  purchase_void: "لغو خرید",
  weaver_return: "برگشت به بافنده",
  "Drafts do not change stock or money until posted.":
    "مسوده تا زمان ثبت نهایی بر موجودی یا حساب تأثیر ندارد.",
  "Draft saved. Open Purchase drafts to edit or post it.":
    "مسوده ذخیره شد. برای ویرایش یا ثبت نهایی، مسوده‌های خرید را باز کنید.",
  "Only unreceived purchases can be voided. Actual payments remain recorded as credit owed by the weaver.":
    "تنها خریدهای دریافت‌نشده قابل لغو هستند. پرداخت‌های واقعی به‌عنوان طلب از بافنده حفظ می‌شوند.",
  "Returns credit the original purchase price. Record an actual refund separately when money is received.":
    "برگشت بر اساس قیمت اصلی خرید محاسبه می‌شود. دریافت واقعی پول را جداگانه به‌عنوان بازپرداخت ثبت کنید.",
  "Corrections preserve history and do not change purchase cost or cash.":
    "اصلاحات تاریخچه را حفظ می‌کنند و قیمت خرید یا نقدینگی را تغییر نمی‌دهند.",
  "No eligible carpets at this source location.":
    "قالین واجد شرایط در این محل مبدا وجود ندارد.",
  "Enter a reason of at least three characters.":
    "دلیلی با حداقل سه حرف وارد کنید.",
  "Stock changed. Reload before correcting it.":
    "موجودی تغییر کرده است. پیش از اصلاح، دوباره بارگیری کنید.",
  "This draft changed or was finalized. Reload it.":
    "مسوده تغییر کرده یا نهایی شده است. دوباره بارگیری کنید.",
  "This purchase is already void.": "این خرید قبلاً لغو شده است.",
  "Cancellation must include every purchase item.":
    "لغو باید تمام قالین‌های خرید را شامل شود.",
  "Only an unreceived purchase can be voided. Use a return for received carpets.":
    "تنها خرید دریافت‌نشده قابل لغو است. برای قالین‌های دریافت‌شده از برگشت استفاده کنید.",
  "Use a stock transfer to change branches.":
    "برای تغییر شعبه از انتقال موجودی استفاده کنید.",
  "The carpet is not eligible for this stock correction.":
    "قالین برای این نوع اصلاح موجودی واجد شرایط نیست.",
  "Return only available carpets from this purchase and source location.":
    "تنها قالین‌های موجود مربوط به این خرید و محل مبدا را برگشت دهید.",
  "This carpet was already returned.": "این قالین قبلاً برگشت داده شده است.",
  "Refund exceeds the credit owed by the weaver.":
    "بازپرداخت بیشتر از طلب موجود از بافنده است.",
  "Refund date cannot precede the cancellation or return.":
    "تاریخ بازپرداخت نباید پیش از لغو یا برگشت باشد.",
  "This reference belongs to a saved draft. Open and post that draft.":
    "این شماره مربوط به یک مسوده ذخیره‌شده است. همان مسوده را باز و ثبت نهایی کنید.",
  Carpets: "قالین‌ها",
  "Weaver purchases": "خرید از بافندگان",
  "Stock receipts": "دریافت موجودی",
  "Stock transfers": "انتقال موجودی",
  "Weaver payments": "پرداخت به بافندگان",
  "Register carpet": "ثبت قالین",
  "Edit carpet": "ویرایش قالین",
  "Post purchase": "ثبت خرید",
  "Receive stock": "دریافت موجودی",
  "Transfer stock": "انتقال موجودی",
  "Record payment": "ثبت پرداخت",
  "Carpet code": "کد قالین",
  "Registration branch": "شعبه ثبت",
  "Size label": "اندازه",
  "Length (cm)": "طول (سانتی‌متر)",
  "Width (cm)": "عرض (سانتی‌متر)",
  Color: "رنگ",
  Material: "جنس",
  Weaver: "بافنده",
  Currency: "ارز",
  "Purchase reference": "شماره خرید",
  "Receipt reference": "شماره دریافت",
  "Transfer reference": "شماره انتقال",
  "Payment reference": "شماره پرداخت",
  "Purchase date": "تاریخ خرید",
  "Received date": "تاریخ دریافت",
  "Transfer date": "تاریخ انتقال",
  "Payment date": "تاریخ پرداخت",
  "From location": "محل مبدا",
  "To location": "محل مقصد",
  "Stock location": "محل موجودی",
  "Payment type": "نوع پرداخت",
  purchase: "پرداخت خرید",
  additional: "پرداخت اضافی",
  "Expense category": "دسته‌بندی مصرف",
  "Purchase to settle": "خرید برای تسویه",
  "Purchase units per payment unit":
    "واحد ارز خرید در برابر یک واحد ارز پرداخت",
  "Each selected carpet is one individually tracked item.":
    "هر قالین انتخاب‌شده یک قلم جداگانه با تاریخچه مستقل است.",
  "Posted purchases and movements are permanent records. Review the details before posting.":
    "خریدها و انتقال‌های ثبت‌شده اسناد دایمی هستند. پیش از ثبت، جزئیات را بررسی کنید.",
  "Bonuses are separate expenses and do not reduce purchase balances.":
    "پرداخت‌های اضافی مصرف جداگانه هستند و بیلانس خرید را کاهش نمی‌دهند.",
  "Payments affect the selected account. Correct a mistake with a reversal.":
    "پرداخت بر حساب انتخاب‌شده تأثیر می‌گذارد. اشتباه را با تراکنش برگشت اصلاح کنید.",
  "Carpet search": "جستجوی قالین",
  "Find carpets": "جستجوی قالین‌ها",
  "Purchase search": "جستجوی خرید",
  "Find purchases": "جستجوی خریدها",
  "Unit price": "قیمت قالین",
  "Selected carpets": "قالین‌های انتخاب‌شده",
  "No matching carpets.": "قالین مطابق جستجو یافت نشد.",
  "No matching purchases.": "خرید مطابق جستجو یافت نشد.",
  "Select at least one carpet.": "حداقل یک قالین انتخاب کنید.",
  "Choose a different destination.": "مقصد دیگری انتخاب کنید.",
  registered: "ثبت‌شده",
  purchased: "خریداری‌شده",
  in_stock: "در موجودی",
  receipt: "دریافت",
  transfer: "انتقال",
  "All stock": "تمام موجودی",
  "All branches": "تمام شعبه‌ها",
  "All locations": "تمام محل‌ها",
  "No records found.": "هیچ سندی یافت نشد.",
  "Search records": "جستجوی اسناد",
  "Saved successfully.": "با موفقیت ثبت شد.",
  "Could not load inventory.": "موجودی بارگیری نشد.",
  "Could not save the record.": "سند ذخیره نشد.",
  "Movement history": "تاریخچه جابجایی",
  "Handled by": "ثبت‌کننده",
  Sequence: "ترتیب",
  "Current location": "محل فعلی",
  "Current status": "وضعیت فعلی",
  "Purchase total": "مجموع خرید",
  "Paid amount": "مبلغ پرداخت‌شده",
  "Unpaid balance": "بیلانس پرداخت‌نشده",
  "Carpet images": "تصاویر قالین",
  "Upload image": "بارگذاری تصویر",
  "Image description": "توضیح تصویر",
  "Delete image": "حذف تصویر",
  "No images yet.": "هنوز تصویری ثبت نشده است.",
  "Choose a JPEG, PNG or WebP image up to 2 MB and 4000 × 4000 pixels.":
    "تصویر JPEG، PNG یا WebP تا ۲ مگابایت و ۴۰۰۰ × ۴۰۰۰ پیکسل انتخاب کنید.",
  "Could not load image.": "تصویر بارگیری نشد.",
  "Image saved.": "تصویر ذخیره شد.",
  "Image deleted.": "تصویر حذف شد.",
  "Choose an image up to 2 MB.": "تصویری تا ۲ مگابایت انتخاب کنید.",
  Upload: "بارگذاری",
  Reversed: "برگشت‌شده",
  "Reverse payment": "برگشت پرداخت",
  "Reversal reason": "دلیل برگشت",
  "Payment reversed.": "پرداخت برگشت داده شد.",
  "Payment currency": "ارز پرداخت",
  "Purchase currency": "ارز خرید",
  "Converted settlement": "مبلغ تسویه تبدیل‌شده",
  "Allocated amount": "مبلغ تخصیص‌یافته",
  "This text is too long.": "این متن بیش از حد طولانی است.",
  "Use 2 to 150 characters.": "از ۲ تا ۱۵۰ حرف استفاده کنید.",
  "Use 3 to 255 characters.": "از ۳ تا ۲۵۵ حرف استفاده کنید.",
  "Enter both length and width.": "طول و عرض را هر دو وارد کنید.",
  "Enter a positive dimension.": "اندازه مثبت وارد کنید.",
  "Enter a valid amount.": "مبلغ معتبر وارد کنید.",
  "Enter a valid exchange rate.": "نرخ تبادله معتبر وارد کنید.",
  "Enter a valid date on or before today.": "تاریخ معتبر تا امروز وارد کنید.",
  "Use up to 40 uppercase letters, digits, hyphens or underscores.":
    "حداکثر ۴۰ حرف بزرگ انگلیسی، عدد، خط تیره یا زیرخط وارد کنید.",
  "Insufficient account balance.": "بیلانس حساب کافی نیست.",
  "Payment exceeds the unpaid purchase balance.":
    "مبلغ پرداخت بیشتر از بیلانس پرداخت‌نشده خرید است.",
  "Same-currency allocations must use a rate of 1.":
    "برای ارز یکسان، نرخ تخصیص باید ۱ باشد.",
  "Payment date cannot precede purchase date.":
    "تاریخ پرداخت نباید پیش از تاریخ خرید باشد.",
  "Choose an active record.": "یک مورد فعال انتخاب کنید.",
  "This reference is already in use.": "این شماره قبلاً استفاده شده است.",
  "Choose unpurchased carpets registered to the purchase branch.":
    "قالین‌های خریداری‌نشده مربوط به شعبه خرید را انتخاب کنید.",
  "The selected carpet is not available at the expected source.":
    "قالین انتخاب‌شده در محل مبدا موجود نیست.",
  "Movement date cannot precede the previous event.":
    "تاریخ جابجایی نباید پیش از رویداد قبلی باشد.",
  "Account and payment must belong to the same branch.":
    "حساب و پرداخت باید مربوط به یک شعبه باشند.",
  "Allocate the entire payment to purchases.":
    "تمام مبلغ پرداخت را به خریدها تخصیص دهید.",
  "Payment allocations must belong to the same weaver and branch.":
    "تخصیص‌های پرداخت باید مربوط به یک بافنده و شعبه باشند.",
  "Stock changed. Reload before receiving or transferring.":
    "موجودی تغییر کرده است. پیش از دریافت یا انتقال، دوباره بارگیری کنید.",
  "This carpet changed. Reload it before editing.":
    "معلومات قالین تغییر کرده است. پیش از ویرایش دوباره بارگیری کنید.",
  "Purchased carpet code and registration branch are fixed.":
    "کد و شعبه ثبت قالین خریداری‌شده قابل تغییر نیست.",
  "This idempotency key belongs to a different request.":
    "این شناسه درخواست قبلاً برای درخواست دیگری استفاده شده است.",
  "A carpet can have at most eight images.":
    "هر قالین می‌تواند حداکثر هشت تصویر داشته باشد.",
};
export function inventoryText(locale: string, text: string): string {
  if (text === "Post draft" || text === "Post purchase") return locale === "fa" ? "خرید و دریافت موجودی" : "Purchase & receive";
  if (locale !== "fa")
    return (
      (
        {
          registered: "Registered",
          purchased: "Purchased",
          at_washing: "At washing",
          washing_completed: "Washing completed",
          production_completed: "Production completed",
          wash_sent: "Sent for washing",
          wash_completed: "Washing completed",
          wash_returned: "Returned from washing",
          washing_dispatch: "Sent for washing",
          washing_complete: "Washing completed",
          washing_return: "Returned from washing",
          sold: "Sold",
          production_receive: "Production received",
          sale: "Sold",
          buy_resell: "Buy & resell",
          company_funded: "Company-funded production",
          partnership: "Profit-sharing partnership",
          in_stock: "In stock",
          purchase: "Purchase",
          additional: "Additional payment",
          receipt: "Receipt",
          transfer: "Transfer",
          missing: "Missing",
          returned: "Returned to weaver",
          draft: "Draft",
          posted: "Posted",
          cancelled: "Cancelled",
          void: "Void",
          all: "All",
          found: "Found",
          relocate: "Correct location",
          adjustment: "Stock correction",
          purchase_void: "Purchase cancelled",
          weaver_return: "Returned to weaver",
        } as Record<string, string>
      )[text] ?? text
    );
  if (dari[text]) return dari[text];
  if (/^The .+ field is required/.test(text)) return "این خانه الزامی است.";
  if (/^The .+ (format is invalid|must be)/.test(text))
    return "مقدار معتبر وارد کنید.";
  if (text === "Select at most 50 distinct carpets.")
    return "حداکثر ۵۰ قالین متفاوت انتخاب کنید.";
  return foundationText(locale, text);
}
